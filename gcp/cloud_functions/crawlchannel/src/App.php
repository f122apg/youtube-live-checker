<?php
namespace F122apg\YoutubeLiveChecker;

use F122apg\YoutubeLiveChecker\GCP\BatchClient;
use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\Workflows;
use F122apg\YoutubeLiveChecker\Youtube\RSSParser;
use F122apg\YoutubeLiveChecker\Youtube\Http;
use F122apg\YoutubeLiveChecker\Youtube\Html;

class App {
    /**
     * GCPのプロジェクトIDを定義したランタイム環境変数
     *
     * @var string
     */
    public const ENV_PROJECT_ID = 'PROJECT_ID';

    /**
     * APIの有効ステータス
     *
     * @var bool
     */
    public static $isAvailableApi = true;

    /**
     * 配信されているかチェックする
     *
     * @param string $channelId チャンネルID
     */
    public static function checkNowLive(string $channelId) {
        Log::info('Live check start:' . (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM));
        Log::info('crawlTarget channelId:' . $channelId);

        // RSSフィードの取得
        $xmlStr = Http::getRSSXml($channelId);
        $parser = new RSSParser($xmlStr);
        // RSSフィードから取得したContentIdを抽出
        $feedContentIds = $parser->getContentIds();
        $batchClient = new BatchClient();

        foreach ($feedContentIds as $contentId) {
            $entry = null;
            if (self::$isAvailableApi) {
                try {
                    // Youtube Data API経由から動画情報を取得
                    $entry = Http::getVideoInfo($contentId);
                } catch (\Exception $ex) {
                    // エラーが発生したら、Quotaに到達したと思われるのでHTMLから動画情報を取得
                    Log::error($ex->getMessage());
                    Log::error('Probably reached the quota limit.');
                    $entry = Html::getVideoInfo($contentId);

                    // 以降の処理ではAPIを使わない
                    self::$isAvailableApi = false;
                }
            } else {
                $entry = Html::getVideoInfo($contentId);
            }

            // 配信中かつ、録画していないコンテンツIDであれば録画を開始する
            if ($entry->isNowLive() && !$batchClient->isRecordingLive($contentId)) {
                Log::info('[' . $contentId . ']: is live! T:CONTENT_ID');
                Log::info(
                    'Rec starting... StartTime:' .
                    (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM) .
                    ' Channel: ' . $entry->channelName .
                    ' ID:' . $entry->contentId
                );

                self::_startRecording($entry->contentTitle, $entry->contentId);
            } else if (!$entry->isNowLive()) {
                Log::info('[' . $contentId . ']: not a live. T:CONTENT_ID');
            } else {
                Log::info('[' . $contentId . ']: is recording. T:CONTENT_ID');
            }
        }

        Log::info('Live check end');
    }

    /**
     * 配信の録画を開始する
     *
     * @param string $title 配信タイトル
     * @param string $contentId コンテンツID
     * @return void
     */
    private static function _startRecording(string $title, string $contentId) {
        Workflows::execute($title, $contentId);
    }
}