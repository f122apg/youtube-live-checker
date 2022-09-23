<?php
namespace f122apg\App\Youtube;

use f122apg\App\Youtube\YoutubeEntry;

class YoutubeFeedParser {
    public array $entries;

    public function __construct(string $xmlStr) {
        $xml = simplexml_load_string($xmlStr);

        foreach ($xml->entry as $entry) {
            $this->entries[] = new YoutubeEntry(
                $xml->children('yt', 'channelId'),
                $xml->title,
                $entry->children('yt', 'videoId'),
                $entry->title,
                'unknown',
                new \DateTime($entry->published)
            );
        }
    }

    public function getContentIds():array {
        return array_map(function($entry) {
            return $entry->contentId;
        }, $this->entries);
    }
}