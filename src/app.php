<?php
namespace F122apg\YoutubeLiveChecker;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\Firestore;
use F122apg\YoutubeLiveChecker\Youtube\RSSParser;
use F122apg\YoutubeLiveChecker\Youtube\Http;

class App {
    private const _YT_DLP_COMMAND = 'yt-dlp -P %s --live-from-start https://www.youtube.com/watch?v=%s';
    private const _ENV_DOWNLOAD_PATH = 'DOWNLOAD_PATH';

    public static function checkNowLive(string $channelId) {
        Log::info('Live check start:' . (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM));

        // RSSフィードの取得
        $xmlStr = Http::getRSSXml($channelId);
        $parser = new RSSParser($xmlStr);
        // RSSフィードから取得したContentIdを抽出
        $feedContentIds = $parser->getContentIds();

        $firestore = new Firestore();
        // DBに登録されていないContentIdを抽出（新しい動画として認識する）
        $newContentIds = $firestore->getNewContentIds($feedContentIds);

        foreach ($newContentIds as $contentId) {
            $entry = Http::getVideoInfo($contentId);
            if ($entry->isSaveItem()) {
                $firestore->addDocument($entry->toFeedDocument());
            }

            Log::info('Found new ContentID:' . (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM));

            if ($entry->isNowLive()) {
                Log::info(
                    'Rec starting... StartTime:' .
                    (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM) .
                    ' Channel: ' . $entry->channelName .
                    ' ID:' . $entry->contentId
                );

                self::_startDownload($entry->contentId);
            } else {
                Log::info('This id is not a live.');
            }
        }

        Log::info('Live check end');
    }

    private static function _startDownload(string $contentId) {
        $baseCommand = sprintf(self::_YT_DLP_COMMAND, getenv(self::_ENV_DOWNLOAD_PATH), $contentId);
        $linuxCommand = $baseCommand . ' > /dev/null &';
        exec($linuxCommand);
    }
}