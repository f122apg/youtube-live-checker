<?php
/**
 * ログ集約処理のテスト。
 *
 * 集約は「意味のない繰り返しを潰す」ためのもので、
 * 種別の異なる障害まで潰してはいけない。両方向を検証する。
 */
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/Analysis/Sanitizer.php';
require __DIR__ . '/../src/GCP/MetadataToken.php';
require __DIR__ . '/../src/GCP/CloudLogging.php';

use F122apg\YoutubeLiveChecker\GCP\CloudLogging;
use F122apg\YoutubeLiveChecker\Analysis\Sanitizer;

$failures = 0; $checks = 0;

function groupKey(string $line): string
{
    static $method = null, $instance = null;
    if ($method === null) {
        $method = new ReflectionMethod(CloudLogging::class, 'normalizeForGrouping');
        $method->setAccessible(true);
        $instance = new CloudLogging('p', 'job-test', new Sanitizer());
    }
    return $method->invoke($instance, $line);
}

function mustDiffer(string $a, string $b, string $label): void
{
    global $failures, $checks; $checks++;
    if (groupKey($a) === groupKey($b)) {
        echo "  [FAIL] {$label}: 同一グループに集約された\n";
        $failures++;
    }
}

function mustMatch(string $a, string $b, string $label): void
{
    global $failures, $checks; $checks++;
    if (groupKey($a) !== groupKey($b)) {
        echo "  [FAIL] {$label}: 集約されなかった\n";
        $failures++;
    }
}

echo "=== 障害種別が違えば分ける ===\n";
mustDiffer('HTTP Error 403 on fragment 10', 'HTTP Error 500 on fragment 10', 'HTTPステータス');
mustDiffer('Task exited with status 1', 'Task exited with status 137', '終了コード(OOM検出に必須)');
mustDiffer('connected to [IP_1]', 'connected to [IP_2]', '仮名化IPの同一性');
mustDiffer('resolve failed (Errno -9)', 'resolve failed (Errno -2)', 'errno');
mustDiffer('killed by signal 9', 'killed by signal 15', 'シグナル');
mustDiffer('Disk free: 100 GB', 'Disk free: 0 GB', 'ディスク空き容量');
mustDiffer('Available memory: 2048 MB', 'Available memory: 0 MB', '空きメモリ');
mustDiffer('No progress for 60 seconds', 'No progress for 1200 seconds', '停止時間');
mustDiffer(
    '[download] Got error: HTTP Error 403: Forbidden. Retrying fragment 10 (1/10)...',
    '[download] Got error: HTTP Error 500: Internal Server Error. Retrying fragment 10 (1/10)...',
    '実ログ形式のHTTPステータス'
);
mustDiffer(
    "Failed to resolve 'rr3---sn-alpha.googlevideo.com' ([Errno -9] Name or service not known)",
    "Failed to resolve 'rr5---sn-beta.googlevideo.com' ([Errno -2] Name or service not known)",
    'CDNホストをまとめてもerrnoは保持'
);

echo "=== 意味のない数値差は集約する ===\n";
mustMatch(
    '[download] Got error: HTTP Error 403: Forbidden. Retrying fragment 1762 (7/10)...',
    '[download] Got error: HTTP Error 403: Forbidden. Retrying fragment 5485 (2/10)...',
    'フラグメント番号'
);
mustMatch(
    "[download] fragment not found; Skipping fragment 5484 ...",
    "[download] fragment not found; Skipping fragment 1201 ...",
    'スキップ対象のフラグメント番号'
);
mustMatch(
    "Failed to resolve 'rr3---sn-alpha.googlevideo.com' ([Errno -9] Name or service not known)",
    "Failed to resolve 'rr5---sn-beta.googlevideo.com' ([Errno -9] Name or service not known)",
    'googlevideo CDNホスト名'
);

echo "=== 少数の別障害が本文から消えない ===\n";
$checks++;
$entries = [];
for ($i = 0; $i < 100; $i++) {
    $entries[] = 'Disk free: 100 GB';
}
// 数値一律置換では同じグループになるが、意味は正反対の1件。
$entries[] = 'Disk free: 0 GB';

$r = new ReflectionMethod(CloudLogging::class, 'collapseRepeatedPatterns');
$r->setAccessible(true);
$instance = new CloudLogging('p', 'job-test', new Sanitizer());
$out = implode("\n", $r->invoke($instance, $entries));

if (!str_contains($out, 'Disk free: 0 GB')) {
    echo "  [FAIL] 100件の正常値に紛れたディスク枯渇が本文から消えた\n";
    $failures++;
}

$checks++;
if (substr_count($out, 'Disk free: 100 GB') > 5) {
    echo "  [FAIL] 完全に同一の繰り返しが集約されていない\n";
    $failures++;
}

$checks++;
if (!str_contains($out, '回省略')) {
    echo "  [FAIL] 集計行が出力されていない\n";
    $failures++;
}

echo "=== 実ログ形式は圧縮しつつ別障害を残す ===\n";
$retryEntries = [];
for ($i = 0; $i < 100; $i++) {
    $attempt = ($i % 10) + 1;
    $retryEntries[] = "[download] Got error: HTTP Error 403: Forbidden. Retrying fragment {$i} ({$attempt}/10)...";
}
$retryEntries[] = '[download] Got error: HTTP Error 500: Internal Server Error. Retrying fragment 999 (1/10)...';
$retryOut = implode("\n", $r->invoke($instance, $retryEntries));

$checks++;
if (substr_count($retryOut, 'HTTP Error 403') > 5) {
    echo "  [FAIL] 403リトライストームが集約されていない\n";
    $failures++;
}

$checks++;
if (!str_contains($retryOut, 'HTTP Error 500')) {
    echo "  [FAIL] 403に紛れた500が本文から消えた\n";
    $failures++;
}

echo "\n";
if ($failures === 0) { echo "OK: {$checks} 件すべて成功\n"; exit(0); }
echo "NG: {$checks} 件中 {$failures} 件失敗\n"; exit(1);
