<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

use F122apg\YoutubeLiveChecker\Youtube\RSSEntry;

class Http {
    private const _YOUTUBE_FEED_XML = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';
    private const _YOUTUBE_API_VIDEO = 'https://content-youtube.googleapis.com/youtube/v3/videos?id=%s&key=%s&part=snippet';
    private const _ENV_YOUTUBE_API_KEY = 'YOUTUBE_API_KEY';

    /**
     * Youtube RSSからXMLを取得する
     *
     * @param string $channelID チャンネルID
     * @return string XML
     */
    public static function getRSSXml(string $channelId): string {
        $url = sprintf(self::_YOUTUBE_FEED_XML, $channelId);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
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

        return $response;
    }

    /**
     * Youtube APIから動画の情報を取得する
     *
     * @param string $contentID コンテンツID
     * @return RSSEntry
     */
    public static function getVideoInfo(string $contentId): RSSEntry {
        $url = sprintf(self::_YOUTUBE_API_VIDEO, $contentId, getenv(self::_ENV_YOUTUBE_API_KEY));
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
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

        $json = json_decode($response);

        return new RSSEntry(
            $json->items[0]->snippet->channelId,
            $json->items[0]->snippet->channelTitle,
            $json->items[0]->id,
            $json->items[0]->snippet->title,
            $json->items[0]->snippet->liveBroadcastContent,
            new \DateTime($json->items[0]->snippet->publishedAt)
        );
    }
}