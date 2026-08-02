<?php
/**
 * Responses API の保存設定が環境変数で安全に切り替わることを検証する。
 */
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/Analysis/Sanitizer.php';
require __DIR__ . '/../src/AI/OpenAI.php';

use F122apg\YoutubeLiveChecker\Analysis\Sanitizer;
use F122apg\YoutubeLiveChecker\AI\OpenAI;

putenv('OPENAI_API_KEY=sk-test-dummy-key-for-unit-test-only');

$failures = 0;
$checks = 0;

function checkStore(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        echo "  [FAIL] {$label}\n";
        $failures++;
    }
}

function requestStoreValue(): bool
{
    $client = new OpenAI(new Sanitizer());
    $method = new ReflectionMethod(OpenAI::class, 'buildRequestBody');
    $method->setAccessible(true);
    $body = $method->invoke($client, 'test input');
    return (bool)$body['store'];
}

echo "=== OPENAI_STORE_RESPONSES ===\n";

putenv('OPENAI_STORE_RESPONSES');
checkStore(requestStoreValue() === false, '未設定時は保存しない');

putenv('OPENAI_STORE_RESPONSES=true');
checkStore(requestStoreValue() === true, 'true で保存する');

putenv('OPENAI_STORE_RESPONSES=0');
checkStore(requestStoreValue() === false, '0 で保存しない');

putenv('OPENAI_STORE_RESPONSES=invalid');
$invalidRejected = false;
try {
    new OpenAI(new Sanitizer());
} catch (RuntimeException $e) {
    $invalidRejected = str_contains($e->getMessage(), 'OPENAI_STORE_RESPONSES must be');
}
checkStore($invalidRejected, '不正値は拒否する');

putenv('OPENAI_STORE_RESPONSES');

echo "\n";
if ($failures === 0) {
    echo "OK: {$checks} 件すべて成功\n";
    exit(0);
}

echo "NG: {$checks} 件中 {$failures} 件失敗\n";
exit(1);
