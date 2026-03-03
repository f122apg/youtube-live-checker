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

// 注意: このCloud Functionはすべてのケースで HTTP 200 を返す。
// Workflow側の try-except でラップされており、4xx/5xx を返すと except に入る。
// ログ分析は補助的な処理であり、失敗しても後続の通知フローを阻害しない設計のため、
// Cloud Function内でエラーを吸収し、常に200を返すことでWorkflowを正常に続行させる。
function main(ServerRequestInterface $request): ResponseInterface
{
    $queries = $request->getQueryParams();

    $jobId = $queries['jobId'] ?? null;
    $contentId = $queries['contentId'] ?? null;
    $title = $queries['title'] ?? null;
    $typeString = $queries['type'] ?? null;
    $projectId = $queries['projectId'] ?? null;
    $batchRegion = $queries['batchRegion'] ?? null;

    if (!$jobId || !$contentId || !$title || !$typeString || !$projectId) {
        Log::warning('Missing required parameters');
        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor(json_encode([
                'status' => 'error',
                'message' => 'Missing required parameters'
            ])));
    }

    $type = AnalysisType::tryFrom($typeString);
    if ($type === null) {
        Log::warning('Invalid analysis type: ' . $typeString);
        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor(json_encode([
                'status' => 'error',
                'message' => 'Invalid type parameter'
            ])));
    }

    // オプションパラメータ（Workflowから渡されるメタデータ）
    $metadata = [
        'retryCount' => $queries['retryCount'] ?? null,
        'elapsedHours' => $queries['elapsedHours'] ?? null,
        'errorMessage' => $queries['errorMessage'] ?? null,
        'workflowExecutionId' => $queries['workflowExecutionId'] ?? null,
        'batchJobState' => $queries['batchJobState'] ?? null,
        'batchStatusReason' => $queries['batchStatusReason'] ?? null,
        'attemptNo' => $queries['attemptNo'] ?? null,
    ];

    try {
        $analyzer = new LogAnalyzer(
            $jobId,
            $contentId,
            $title,
            $type,
            $projectId,
            $batchRegion ?? 'us-central1',
            $metadata
        );
        $result = $analyzer->execute();

        Log::info('Analysis completed successfully for job: ' . $jobId);
        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor(json_encode([
                'status' => 'success',
                'notionPageId' => $result['notionPageId'] ?? null,
            ])));
    } catch (\Throwable $e) {
        Log::error('Analysis failed: ' . $e->getMessage());
        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ])));
    }
}
