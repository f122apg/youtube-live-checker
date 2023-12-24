<?php
require __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\CloudEvent;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use F122apg\YoutubeLiveChecker\App;
use F122apg\YoutubeLiveChecker\Log;

function main(CloudEvent $cloudevent): ResponseInterface
{
    $pubsubData = base64_decode($cloudevent->getData()['message']['data']);
    $channelIds = explode(',', $pubsubData);

    if (!empty($channelIds)) {
        $totalCount = count($channelIds);
        Log::info('Live check start:' . (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM));
        Log::info('crawlTargetTotal: ' . $totalCount);
        $counter = 1;

        foreach ($channelIds as $channelId) {
            Log::info('crawlCount ' . $counter . '/' . $totalCount);
            App::checkNowLive($channelId);

            $counter ++;
        }

        Log::info('Live check end');

        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor('success'));
    }

    return (new Response())
        ->withStatus(400)
        ->withBody(Utils::streamFor('Must need channelId'));
}
