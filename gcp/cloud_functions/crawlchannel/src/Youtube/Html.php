<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

use F122apg\YoutubeLiveChecker\Youtube\RSSEntry;

class Html {
    private const _YOUTUBE_URL = 'https://www.youtube.com/watch?v=%s';
    private const _USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const _YOUTUBE_JSON_START_STR = 'var ytInitialPlayerResponse = ';
    private const _YOUTUBE_JSON_END_STR = '};var meta';

    /**
     * HTMLから動画の情報を取得する
     *
     * @param string $contentID コンテンツID
     * @return RSSEntry
     */
    public static function getVideoInfo(string $contentId) {
        $url = sprintf(self::_YOUTUBE_URL, $contentId);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . self::_USER_AGENT
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYSTATUS, false);

        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($statusCode != 200) {
            throw new \RuntimeException(
                'HTTP ERROR! URL:' . $url . ' StatusCode:' . $statusCode . ' Response:' . $response . ' err:' . curl_error($curl)
            );
        }

        // HTMLからjsonを取得する
        $jsonStartPos = mb_strpos($response, self::_YOUTUBE_JSON_START_STR) + mb_strlen(self::_YOUTUBE_JSON_START_STR);
        $jsonEndPos = mb_strpos($response, self::_YOUTUBE_JSON_END_STR) + 1;
        $json = mb_substr($response, $jsonStartPos, $jsonEndPos - $jsonStartPos);
        $jsonArray = json_decode($json, true);

        // jsonから配信ステータスを取得
        $isLiveStatus = self::_getLiveStatus($jsonArray);

        return new RSSEntry(
            $jsonArray['videoDetails']['channelId'],
            $jsonArray['microformat']['playerMicroformatRenderer']['ownerChannelName'],
            $jsonArray['videoDetails']['videoId'],
            $jsonArray['videoDetails']['title'],
            $isLiveStatus,
            new \DateTime($jsonArray['microformat']['playerMicroformatRenderer']['publishDate'] ?? 'now')
        );
    }

    /**
     * 配信のステータスをjsonから読み取る
     *
     * @param array $json YoutubeのHTMLから取得したjson配列
     * @return string 配信ステータス
     */
    private static function _getLiveStatus($json) {
        // isLiveContentがfalseなら普通の動画
        $isLiveContent = $json['videoDetails']['isLiveContent'];
        if (!$isLiveContent) {
            return RSSEntry::LIVE_STATUS_NONE;
        }

        // isLiveNowがtrueなら配信中
        $isLiveNow = $json['microformat']['playerMicroformatRenderer']['liveBroadcastDetails']['isLiveNow'];
        if ($isLiveNow) {
            return RSSEntry::LIVE_STATUS_LIVE;
        } else {
            // endTimestampがなければ配信開始前で、あれば配信終了後
            $hasEndTimestamp = $json['microformat']['playerMicroformatRenderer']['liveBroadcastDetails']['endTimestamp'] ?? false;

            return $hasEndTimestamp
                ? RSSEntry::LIVE_STATUS_NONE
                : RSSEntry::LIVE_STATUS_UPCOMING;
        }
    }
}