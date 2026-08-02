<?php
require __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use F122apg\YoutubeLiveChecker\AWS\Sns;
use F122apg\YoutubeLiveChecker\Notification\NotificationType;

FunctionsFramework::http('main', 'main');

function main(ServerRequestInterface $request): ResponseInterface
{
    $queries = $request->getQueryParams();

    if (!empty($queries) && isset($queries['notify']) && $queries['notify']) {
        // 通知タイプを判定（後方互換性のため、typeがない場合は'start'）
        $typeString = $queries['type'] ?? 'start';
        $type = NotificationType::tryFrom($typeString) ?? NotificationType::START;

        // メタデータを収集
        // summary / analysisUrl は rec-log-analyzer の分析結果。
        // 通知を見た時点で原因が分かるようにするため本文の先頭に出す
        $metadata = [
            'jobId' => $queries['jobId'] ?? null,
            'retryCount' => $queries['retryCount'] ?? null,
            'maxRetries' => $queries['maxRetries'] ?? null,
            'elapsedHours' => $queries['elapsedHours'] ?? null,
            'errorMessage' => $queries['errorMessage'] ?? null,
            'summary' => $queries['summary'] ?? null,
            'analysisUrl' => $queries['analysisUrl'] ?? null,
        ];

        $sns = new Sns(
            $queries['title'],
            $queries['contentId'],
            $type,
            $metadata
        );
        $sns->publish();

        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor('success'));
    }

    return (new Response())
        ->withStatus(400)
        ->withBody(Utils::streamFor('Must need channelId'));
}
