<?php
/**
 * トークン予算超過時も、ログ以外の構造を壊さず fail-closed になることを検査する。
 * API計数部分は決定的な偽カウンタに差し替えるため、外部通信は行わない。
 */
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/Analysis/Sanitizer.php';
require __DIR__ . '/../src/Analysis/AnalysisType.php';
require __DIR__ . '/../src/AI/OpenAI.php';

use F122apg\YoutubeLiveChecker\Analysis\Sanitizer;
use F122apg\YoutubeLiveChecker\AI\OpenAI;

putenv('OPENAI_API_KEY=sk-test-dummy-key-for-unit-test-only');
putenv('OPENAI_TOKEN_BUDGET=18000');

$failures = 0; $checks = 0;

function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        echo "  [FAIL] {$label}\n";
        $failures++;
    }
}

$openAi = new OpenAI(new Sanitizer());
$enforce = new ReflectionMethod(OpenAI::class, 'enforceTokenBudget');
$enforce->setAccessible(true);

$rebuild = static fn (string $log): array => [
    'model' => 'gpt-test',
    'input' => [[
        'role' => 'user',
        'content' => "<incident>fixed</incident>\n<logs>\n{$log}\n</logs>\n<source>fixed source</source>",
    ]],
    'max_output_tokens' => 16384,
];

echo "=== 予算内は計数1回で変更しない ===\n";
$calls = 0;
$counter = static function (array $body) use (&$calls): int {
    $calls++;
    return 1000 + (int)ceil(mb_strlen((string)$body['input'][0]['content']) / 10);
};
$smallLog = 'short diagnostic log';
$smallRequest = $rebuild($smallLog);
$smallResult = $enforce->invoke($openAi, $smallRequest, $smallLog, $rebuild, $counter);
check($smallResult === $smallRequest, '予算内リクエストが変更された');
check($calls === 1, '予算内でも複数回計数した');

echo "=== 超過時はログ中間だけを削る ===\n";
$calls = 0;
$largeLog = 'LOG_HEAD_SENTINEL ' . str_repeat('fragment retry 403 ', 1500) . ' LOG_TAIL_SENTINEL';
$largeResult = $enforce->invoke($openAi, $rebuild($largeLog), $largeLog, $rebuild, $counter);
$content = (string)$largeResult['input'][0]['content'];

check(str_contains($content, 'LOG_HEAD_SENTINEL'), 'ログ先頭が失われた');
check(str_contains($content, 'LOG_TAIL_SENTINEL'), 'ログ末尾が失われた');
check(str_contains($content, 'ログ中間を省略'), '省略マーカーがない');
check(str_contains($content, '<incident>fixed</incident>'), 'incident が壊れた');
check(str_contains($content, "</logs>\n<source>fixed source</source>"), 'logs/source 境界が壊れた');
check(mb_strlen($content) < mb_strlen($rebuild($largeLog)['input'][0]['content']), '入力が縮小されていない');
check($calls === 3, '通常の超過処理が計数3回で完了しなかった');

echo "=== 固定部分だけで超過する場合は送信しない ===\n";
$alwaysTooLarge = static fn (array $body): int => 2000;
$threw = false;
try {
    $enforce->invoke($openAi, $rebuild('log'), 'log', $rebuild, $alwaysTooLarge);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'no safe token budget remains');
}
check($threw, '固定部分の超過が fail-closed にならない');

putenv('OPENAI_TOKEN_BUDGET');

echo "\n";
if ($failures === 0) { echo "OK: {$checks} 件すべて成功\n"; exit(0); }
echo "NG: {$checks} 件中 {$failures} 件失敗\n"; exit(1);
