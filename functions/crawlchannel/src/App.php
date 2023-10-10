<?php
namespace F122apg\YoutubeLiveChecker;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\Firestore;
use F122apg\YoutubeLiveChecker\GCP\Workflows;
use F122apg\YoutubeLiveChecker\Youtube\RSSParser;
use F122apg\YoutubeLiveChecker\Youtube\Http;

class App {
    /**
     * GCPのプロジェクトIDを定義したランタイム環境変数
     *
     * @var string
     */
    public const ENV_PROJECT_ID = 'PROJECT_ID';

    /**
     * GCPのRegion
     *
     * @var string
     */
    public const ENV_REGION = 'REGION';

    public static function checkNowLive(string $channelId) {
        Log::info('Live check start:' . (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM));
        Log::info('crawlTarget channelId:' . $channelId);

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

            Log::info('Found new ContentID:' . $entry->contentId);

            if ($entry->isNowLive()) {
                Log::info(
                    'Rec starting... StartTime:' .
                    (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM) .
                    ' Channel: ' . $entry->channelName .
                    ' ID:' . $entry->contentId
                );

                self::_startDownload($entry->contentTitle, $entry->contentId);
            } else {
                Log::info('This id is not a live.');
            }
        }

        // 定期的に古いContentIDを削除する
        // そうしないと、FirestoreのRead OPSが凄い数になるため
        Log::info('Deleting documents...');
        $firestore->deleteDocuments($channelId, $feedContentIds);

        Log::info('Live check end');
    }

    private static function _startDownload(string $title, string $contentId) {
        Workflows::execute($title, $contentId);
    }
}