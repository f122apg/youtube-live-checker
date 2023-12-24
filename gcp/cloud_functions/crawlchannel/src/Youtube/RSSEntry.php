<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

use F122apg\YoutubeLiveChecker\GCP\ContentTypeEnum;

class RSSEntry {
    public const LIVE_STATUS_NONE = 'none';
    public const LIVE_STATUS_UPCOMING = 'upcoming';
    public const LIVE_STATUS_LIVE = 'live';

    /**
     * コンテンツタイプ
     *
     * @var ContentTypeEnum
     */
    public ContentTypeEnum $contentType;

    /**
     * コンストラクタ
     *
     * @param string $channelId チャンネルID
     * @param string $channelName チャンネル名
     * @param string $contentId コンテンツID
     * @param string $contentTitle 動画タイトル
     * @param string $contentType コンテンツタイプ
     * @param \DateTime $publishDate 動画/配信の公開日時
     * @return void
     */
    public function __construct(
        public string $channelId,
        public string $channelName,
        public string $contentId,
        public string $contentTitle,
        public string $liveStatus,
        public \DateTime $publishDate,
    ) {
        // liveStatusから配信か、動画か判定する（配信が終了していた場合、それも動画となる）
        $this->contentType = $liveStatus === self::LIVE_STATUS_LIVE || $liveStatus === self::LIVE_STATUS_UPCOMING
            ? ContentTypeEnum::live
            : ContentTypeEnum::video;
    }

    /**
     * 配信中であるか
     *
     * @return bool
     */
    public function isNowLive(): bool {
        return $this->liveStatus === self::LIVE_STATUS_LIVE;
    }
}