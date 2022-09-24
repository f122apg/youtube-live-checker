<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\GCP\ContentTypeEnum;

class FeedDocument {
    /**
     * コンストラクタ
     *
     * @param string $channelId チャンネルID
     * @param string $channelName チャンネル名
     * @param string $contentId コンテンツID
     * @param string $contentTitle 動画タイトル
     * @param ContentTypeEnum $contentType コンテンツタイプ
     * @param DateTime $publishDate 動画/配信の公開日時
     * @param DateTime $checkedDate プログラムがチェックした時間
     * @return void
     */
    public function __construct(
        private string $channelId,
        private string $channelName,
        private string $contentId,
        private string $contentTitle,
        private ContentTypeEnum $contentType,
        private \DateTime $publishDate,
        private \DateTime $checkedDate
    ) {}

    /**
     * FeedDocumentを配列に変換する
     *
     * @return array
     */
    public function getArrayObject() {
        return json_decode(json_encode($this), true);
    }
}