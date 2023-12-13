<?php
namespace F122apg\YoutubeLiveChecker\Youtube;

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
        $this->contentType = $liveStatus === self::_LIVE_STATUS_LIVE || $liveStatus === self::_LIVE_STATUS_UPCOMING
            ? ContentTypeEnum::live
            : ContentTypeEnum::video;
    }

    /**
     * Firestoreのドキュメントからインスタンスを生成する
     *
     * @param array $document Firestoreのドキュメントの１つ
     * @return RSSEntry
     */
    public static function fromFirestore($document): RSSEntry {
        return new static(
            $document['channelId'],
            $document['channelName'],
            $document['contentId'],
            $document['contentTitle'],
            $document['liveStatus'],
            new \DateTime($document['publishDate']),
        );
    }

    /**
     * 配信中であるか
     *
     * @return bool
     */
    public function isNowLive(): bool {
        return $this->liveStatus === self::_LIVE_STATUS_LIVE;
    }
}