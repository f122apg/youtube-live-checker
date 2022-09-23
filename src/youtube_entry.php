<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

class YoutubeEntry {
    public string $contentType;

    public function __construct(
        public string $channelId, public string $channelName,
        public string $contentId, public string $title,
        public string $liveStatus, public \DateTime $publishDate,
    ) {
        $this->contentType = $liveStatus === 'live' || $liveStatus === 'upcoming'
            ? 'live'
            : 'video';
    }

    public function isSaveRecord() {
        return $this->liveStatus !== 'upcoming';
    }

    public function isNowLive() {
        return $this->liveStatus === 'live';
    }
}