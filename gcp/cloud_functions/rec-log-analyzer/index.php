<?php
require __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\Analysis\LogAnalyzer;
use F122apg\YoutubeLiveChecker\Analysis\AnalysisType;

FunctionsFramework::http('main', 'main');

/**
 * 録画失敗・タイムアウトのログ分析。
 *
 * 常に HTTP 200 を返す。Workflow の try-except でラップされており、
 * 4xx/5xx を返すと except に入って後続の通知フローが乱れるため。
 * 失敗はレスポンスボディの status と Cloud Logging の ERROR で表現する。
 *
 * 参照先のプロジェクトはリクエストではなく環境変数で固定する。
 * 外部から指定できると、このサービスを踏み台にしてサービスアカウントが
 * 読める範囲のデータを外部へ転送できてしまうため。
 *
 * クエリパラメータ:
 *   必須  jobId      Batch のジョブ名 (job-live-download-xxxxx)
 *   任意  jobUid     Batch のジョブUID。省略時は Batch API から解決する
 *                    ※ Cloud Logging の labels.job_uid はジョブ名ではなくUID
 *   必須  type       failure | timeout
 *   任意  contentId / title      手動再実行時は省略可
 *   任意  batchRegion            既定 us-central1
 *   任意  model      モデルの上書き (デバッグ用。例 gpt-5.4-mini)
 *   任意  effort     reasoning effort の上書き (none/minimal/low/medium/high/xhigh/max)
 *   任意  retryCount / elapsedHours / errorMessage ほかメタデータ
 *
 * 手動再実行の例:
 *   GET ?jobId=job-live-download-1785661661&type=failure&model=gpt-5.4-mini
 */
function main(ServerRequestInterface $request): ResponseInterface
{
    $queries = $request->getQueryParams();

    $jobId = trim((string)($queries['jobId'] ?? ''));
    $typeString = trim((string)($queries['type'] ?? ''));

    $projectId = trim((string)(getenv('PROJECT_ID') ?: ''));
    if ($projectId === '') {
        Log::error('PROJECT_ID environment variable is not set');
        return respond('error', ['message' => 'PROJECT_ID is not configured'], true);
    }

    if ($jobId === '' || $typeString === '') {
        return respond('error', ['message' => 'jobId and type are required'], true);
    }

    // Batch API のパスとログ検索に使う。書式を絞って想定外の値を弾く
    if (preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $jobId) !== 1) {
        return respond('error', ['message' => 'jobId has an unexpected format'], true);
    }

    $jobUid = trim((string)($queries['jobUid'] ?? ''));
    if ($jobUid !== '' && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $jobUid) !== 1) {
        return respond('error', ['message' => 'jobUid has an unexpected format'], true);
    }

    $batchRegion = trim((string)($queries['batchRegion'] ?? '')) ?: 'us-central1';
    if (preg_match('/^[a-z0-9\-]{1,32}$/', $batchRegion) !== 1) {
        return respond('error', ['message' => 'batchRegion has an unexpected format'], true);
    }

    $type = AnalysisType::tryFrom($typeString);
    if ($type === null) {
        return respond('error', ['message' => 'Invalid type: ' . $typeString], true);
    }

    // 手動再実行では contentId / title が分からないことがあるので既定値を入れる
    $contentId = trim((string)($queries['contentId'] ?? '')) ?: 'unknown';
    $title = trim((string)($queries['title'] ?? '')) ?: $jobId;

    $metadata = [
        'retryCount' => $queries['retryCount'] ?? null,
        'elapsedHours' => $queries['elapsedHours'] ?? null,
        'errorMessage' => $queries['errorMessage'] ?? null,
        'workflowExecutionId' => $queries['workflowExecutionId'] ?? null,
        'attemptNo' => $queries['attemptNo'] ?? null,
        'executor' => $queries['executor'] ?? null,
    ];

    try {
        $analyzer = new LogAnalyzer(
            $jobId,
            $jobUid ?: null,
            $contentId,
            $title,
            $type,
            $projectId,
            $batchRegion,
            $metadata,
            trim((string)($queries['model'] ?? '')) ?: null,
            trim((string)($queries['effort'] ?? '')) ?: null
        );

        $result = $analyzer->execute();

        return respond('success', [
            'summary' => $result['summary'],
            'classification' => $result['classification'],
            'notionPageId' => $result['notionPageId'],
            'notionUrl' => $result['notionUrl'],
            'usage' => $result['usage'],
        ]);
    } catch (\Throwable $e) {
        Log::error('Analysis failed: ' . $e->getMessage());

        // 通知には「分析できなかった」ことを載せる。無言で成功扱いにしない
        return respond('error', [
            'message' => $e->getMessage(),
            'summary' => 'ログ分析に失敗したため原因を特定できませんでした。Cloud Logging の rec-log-analyzer を確認してください。',
            'classification' => 'unknown',
        ], true);
    }
}

/**
 * @param array<string, mixed> $payload
 */
function respond(string $status, array $payload, bool $isError = false): ResponseInterface
{
    if ($isError) {
        Log::warning('Responding with error status: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $body = json_encode(
        ['status' => $status] + $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return (new Response())
        ->withStatus(200)
        ->withHeader('Content-Type', 'application/json')
        ->withBody(Utils::streamFor($body !== false ? $body : '{"status":"error"}'));
}
