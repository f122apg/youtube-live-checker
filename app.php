<?php
namespace f122apg\App;

use f122apg\App\Database\Database;
use f122apg\App\Youtube\YoutubeFeedParser;
use f122apg\App\Youtube\YoutubeHttp;

class App {
    private const _YT_DLP_COMMAND = '%s --live-from-start https://www.youtube.com/watch?v=%s';
    public const INI_FILE = 'setting.ini';

    public static function liveCheck(string $channelId) {
        echo 'live check start:' . (new \DateTime())->format('Y/m/d H:i:s') . "\n";

        $ini = parse_ini_file(self::INI_FILE);

        // データベースへの接続
        $db = new Database($ini['database_name']);

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
        $ini = parse_ini_file(self::INI_FILE);

        chdir($ini['download_path']);
        $command = sprintf(self::_YT_DLP_COMMAND, $ini['yt_dlp_path'], $contentId);

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