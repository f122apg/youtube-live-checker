<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

use F122apg\YoutubeLiveChecker\GCP\FeedDocument;
use F122apg\YoutubeLiveChecker\GCP\ContentTypeEnum;

class RSSEntry {
    private const _LIVE_STATUS_NONE = 'none';
    private const _LIVE_STATUS_UPCOMING = 'upcoming';
    private const _LIVE_STATUS_LIVE = 'live';

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
     * @param string $publishDate 動画/配信の公開日時
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
        $this->contentType = $liveStatus === self::_LIVE_STATUS_LIVE || $liveStatus === self::_LIVE_STATUS_UPCOMING
            ? ContentTypeEnum::live
            : ContentTypeEnum::video;
    }

    /**
     * DBに保存するか
     * upcomingだった場合、配信がこれから始まる可能性があるため、
     * 次回もチェック対象とするため、DBには保存しない
     *
     * @return bool
     */
    public function isSaveItem(): bool {
        return $this->liveStatus !== self::_LIVE_STATUS_UPCOMING;
    }

    /**
     * 配信中であるか
     *
     * @return bool
     */
    public function isNowLive(): bool {
        return $this->liveStatus === self::_LIVE_STATUS_LIVE;
    }

    /**
     * FeedDocumentへ変換する
     *
     * @return FeedDocument
     */
    public function toFeedDocument(): FeedDocument {
        return new FeedDocument(
            $this->channelId,
            $this->channelName,
            $this->contentId,
            $this->contentTitle,
            $this->contentType,
            $this->publishDate,
            (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM)
        );
    }
}