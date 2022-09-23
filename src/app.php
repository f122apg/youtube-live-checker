<?php
namespace F122apg\YoutubeLiveChecker;

use F122apg\YoutubeLiveChecker\Database;
use F122apg\YoutubeLiveChecker\Youtube\YoutubeFeedParser;
use F122apg\YoutubeLiveChecker\Youtube\YoutubeHttp;

class App {
    private const _YT_DLP_COMMAND = '%s --live-from-start https://www.youtube.com/watch?v=%s';
    private const _INI_FILE = 'setting.ini';

    public static function getConfig() {
        return parse_ini_file(__DIR__ . '/../' . self::_INI_FILE);
    }

    public static function liveCheck(string $channelId) {
        echo 'live check start:' . (new \DateTime())->format('Y/m/d H:i:s') . "\n";

        $config = self::getConfig();

        // データベースへの接続
        $db = new Database($config['database_name']);

        // RSSフィードの取得
        $xmlStr = YoutubeHttp::getFeedXml($channelId);
        $parser = new YoutubeFeedParser($xmlStr);
        // RSSフィードから取得したContentIdを抽出
        $feedContentIds = $parser->getContentIds();

        // DBに登録されていないContentIdを抽出（新しい動画として認識する）
        $newContentIds = $db->getNewContentIds($feedContentIds);

        foreach ($newContentIds as $contentId) {
            $entry = YoutubeHttp::getVideoInfo($contentId);
            if ($entry->isSaveRecord()) {
                $db->insertYoutubeEntry($entry);
            }

            echo 'found new ContentID:' .  $entry->contentId . "\n";

            if ($entry->isNowLive()) {
                echo 'rec starting... StartTime:' . (new \DateTime())->format('Y/m/d H:i:s') . ' Channel: ' . $entry->channelName . ' ID:' . $entry->contentId . "\n";
                self::_startDownload($entry->contentId);
            } else {
                echo 'this id is not a live.' . "\n";
            }
        }

        echo 'live check end' . "\n";
    }

    private static function _startDownload(string $contentId) {
        $config = self::getConfig();

        chdir($config['download_path']);
        $command = sprintf(self::_YT_DLP_COMMAND, $config['yt_dlp_path'], $contentId);

        //Windowsの場合はpopen関数で非同期実行
        if (strpos(PHP_OS, 'WIN')!==false) {
            $winCommand = 'start powershell -Command ' . $command;
            $fp = popen($winCommand, 'r');
            pclose($fp);
        //Linuxの場合はexec関数で非同期実行
        } else {
            $linuxCommand = $command . ' > /dev/null &';
            exec($linuxCommand);
        }
    }
}