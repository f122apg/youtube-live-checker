<?php
/**
 * Sanitizer の漏えい防止テスト。
 *
 * ダミー資格情報を含む実ログ相当の文字列を通し、外部へ出てはいけない値が
 * 残っていないことを確認する。php tests/SanitizerTest.php で実行する。
 */
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/Analysis/Sanitizer.php';

use F122apg\YoutubeLiveChecker\Analysis\Sanitizer;

$failures = 0;
$checks = 0;

function mustNotContain(string $output, string $needle, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (str_contains($output, $needle)) {
        echo "  [FAIL] {$label}: 出力に残存 -> {$needle}\n";
        $failures++;
    }
}

function mustContain(string $output, string $needle, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!str_contains($output, $needle)) {
        echo "  [FAIL] {$label}: 期待する内容がない -> {$needle}\n";
        $failures++;
    }
}

echo "=== 資格情報のマスク ===\n";
$s = new Sanitizer();
$input = implode("\n", [
    'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE',
    'aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    'AWS_SESSION_TOKEN: FwoGZXIvYXdzEXAMPLETOKENVALUE1234567890',
    'YOUTUBE_API_KEY=AIzaSyA1234567890abcdefghijklmnopqrstuv',
    'OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456',
    'GITHUB_TOKEN=ghp_abcdefghijklmnopqrstuvwxyz0123456789',
    'Authorization: Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnop',
    'Set-Cookie: SID=AbCdEfGhIjKlMnOp; Domain=.youtube.com',
    'password: "hunter2superSecret"',
    'curl https://user:p4ssw0rd@example.com/private',
]);
$out = $s->sanitize($input);
mustNotContain($out, 'AKIAIOSFODNN7EXAMPLE', 'AWSアクセスキー');
mustNotContain($out, 'wJalrXUtnFEMI', 'AWSシークレット');
mustNotContain($out, 'FwoGZXIvYXdzEXAMPLE', 'AWSセッショントークン');
mustNotContain($out, 'AIzaSyA1234567890abcdefghijklmnopqrstuv', 'Google APIキー');
mustNotContain($out, 'sk-proj-abcdefghijklmnop', 'OpenAIキー');
mustNotContain($out, 'ghp_abcdefghijklmnop', 'GitHubトークン');
mustNotContain($out, 'eyJhbGciOiJSUzI1NiI', 'JWT');
mustNotContain($out, 'AbCdEfGhIjKlMnOp', 'Cookie');
mustNotContain($out, 'hunter2superSecret', 'パスワード');
mustNotContain($out, 'p4ssw0rd', 'URL埋め込み認証情報');

echo "=== 署名付きURL ===\n";
$s2 = new Sanitizer();
$out2 = $s2->sanitize(
    "[download] https://rr3---sn-abc.googlevideo.com/videoplayback?expire=1785&sig=AJfQdSs123&pot=XyZ\n"
    . "PUT https://bucket.s3.amazonaws.com/o?X-Amz-Signature=deadbeefcafe"
);
mustNotContain($out2, 'sig=AJfQdSs123', 'googlevideo署名');
mustNotContain($out2, 'googlevideo.com', 'メディアURL全体');
mustNotContain($out2, 'X-Amz-Signature=deadbeefcafe', 'S3署名');

echo "=== IPの決定論的仮名化 ===\n";
$s3 = new Sanitizer();
$out3 = $s3->sanitize(implode("\n", [
    '[VPN-INFO] VPN connection established successfully with 210.198.250.157!',
    '[VPN-INFO] External IP: 106.179.176.147',
    '[VPN-INFO] Assigned IP: 10.211.1.53',
    '[VPN-INFO] reconnecting to 210.198.250.157',
    'metadata server 169.254.169.254',
]));
mustNotContain($out3, '210.198.250.157', 'VPNサーバーIP');
mustNotContain($out3, '106.179.176.147', 'VPN出口IP');
mustContain($out3, '10.211.1.53', 'プライベートIPは保持');
mustContain($out3, '169.254.169.254', 'メタデータIPは保持');
// 同じIPは同じ仮名になること
$checks++;
if (substr_count($out3, '[IP_1]') !== 2) {
    echo "  [FAIL] 同一IPの仮名が一致しない: " . substr_count($out3, '[IP_1]') . "回\n";
    $failures++;
}

echo "=== 分析に必要な情報は残る ===\n";
$s4 = new Sanitizer();
$out4 = $s4->sanitize(implode("\n", [
    'ERROR: unable to download video data: HTTP Error 403: Forbidden',
    '[download] Destination: /work/aIozDNoyLkM.f401.mp4',
    'Stall detected (403 storm): no progress in /work for 1200s',
    'Task action/STARTUP/0/0/group0 runnable 0 exited with status 137',
]));
mustContain($out4, 'HTTP Error 403', 'エラー内容');
mustContain($out4, 'aIozDNoyLkM', 'コンテンツID');
mustContain($out4, 'Stall detected', 'ストール検知');
mustContain($out4, 'status 137', '終了コード');

echo "=== 人間向け表記・CLI形式の秘密値 ===
";
$s6 = new Sanitizer();
$out6 = $s6->sanitize(implode("
", [
    'AWS Secret Access Key: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    'API key: abcdefghijklmnopqrstuvwxyz123456',
    'Access Token = ya29shortvalue',
    'client secret : "sp ace d"',
    'curl --password hunter2 --url https://example.com',
    'aws configure --api-key=abc123def456',
    '--token abc123',
]));
mustNotContain($out6, 'wJalrXUtnFEMI', '空白区切りのSecret Access Key');
mustNotContain($out6, 'abcdefghijklmnopqrstuvwxyz123456', '空白区切りのAPI key');
mustNotContain($out6, 'ya29shortvalue', 'Access Token');
mustNotContain($out6, 'sp ace d', '引用符付きclient secret');
mustNotContain($out6, 'hunter2', 'CLI引数 --password');
mustNotContain($out6, 'abc123def456', 'CLI引数 --api-key=');
mustNotContain($out6, '--token abc123', 'CLI引数 --token');

echo "=== 秘密名を含まないCLI引数は残る ===
";
$s7 = new Sanitizer();
$out7 = $s7->sanitize('yt-dlp --socket-timeout 300 --retries 10 --fragment-retries 10 -- aIozDNoyLkM');
mustContain($out7, '--socket-timeout 300', 'yt-dlpのオプション');
mustContain($out7, '--retries 10', 'リトライ設定');
mustContain($out7, 'aIozDNoyLkM', 'コンテンツID');

echo "=== 修飾語付きCLI引数 ===
";
$s8 = new Sanitizer();
$out8 = $s8->sanitize(implode("
", [
    'tool --aws-secret-access-key wJalrXUtnFEMI/K7MDENG',
    'tool --db-password hunter2',
    'tool --openai-api-key abcdef123456',
]));
mustNotContain($out8, 'wJalrXUtnFEMI', '--aws-secret-access-key');
mustNotContain($out8, 'hunter2', '--db-password');
mustNotContain($out8, 'abcdef123456', '--openai-api-key');

echo "=== 実運用のコマンドラインは保持 ===
";
$s9 = new Sanitizer();
$out9 = $s9->sanitize(implode("
", [
    'yt-dlp -v -4 --live-from-start --write-info-json',
    'docker run --cap-add=NET_ADMIN --device=/dev/net/tun --rm',
    'timeout -s SIGTERM -k 60 86400 ./yt-dlp',
]));
mustContain($out9, '--live-from-start', 'yt-dlpのライブ指定');
mustContain($out9, '--cap-add=NET_ADMIN', 'dockerのcap指定');
mustContain($out9, '--device=/dev/net/tun', 'tunデバイス指定');
mustContain($out9, '-k 60 86400', 'timeoutの引数');

echo "=== fail-closed: 残存検知で例外 ===\n";
$checks++;
$s5 = new Sanitizer();
$reflection = new ReflectionMethod(Sanitizer::class, 'assertNoResidualSecrets');
$reflection->setAccessible(true);
try {
    $reflection->invoke($s5, 'leftover AKIAIOSFODNN7EXAMPLE here');
    echo "  [FAIL] 残存を検知できていない\n";
    $failures++;
} catch (\RuntimeException $e) {
    // 期待通り
}

echo "\n";
if ($failures === 0) {
    echo "OK: {$checks} 件すべて成功\n";
    exit(0);
}
echo "NG: {$checks} 件中 {$failures} 件失敗\n";
exit(1);
