<?php
require __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

use F122apg\YoutubeLiveChecker\AWS\Sns;

FunctionsFramework::http('main', 'main');

function main(ServerRequestInterface $request): ResponseInterface
{
    $queries = $request->getQueryParams();

    if (!empty($queries) && isset($queries['notify']) && $queries['notify']) {
        $sns = new Sns($queries['title'], $queries['contentId']);
        $sns->publish();

        return (new Response())
            ->withStatus(200)
            ->withBody(Utils::streamFor('success'));
    }

    return (new Response())
        ->withStatus(400)
        ->withBody(Utils::streamFor('Must need channelId'));
}