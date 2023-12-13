<?php
require __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\CloudEvent;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use F122apg\YoutubeLiveChecker\App;

function main(CloudEvent $cloudevent): ResponseInterface
{
    $pubsubData = base64_decode($cloudevent->getData()['message']['data']);
    $channelIds = explode(',', $pubsubData);

    if (!empty($channelIds)) {
        foreach ($channelIds as $channelId) {
            App::checkNowLive($channelId);
        }

        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor('success'));
    }

    return (new Response())
        ->withStatus(400)
        ->withBody(Utils::streamFor('Must need channelId'));
}
