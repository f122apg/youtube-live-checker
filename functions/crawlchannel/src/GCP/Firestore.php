<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use Google\Cloud\Firestore\FirestoreClient;

use F122apg\YoutubeLiveChecker\App;
use F122apg\YoutubeLiveChecker\GCP\FeedDocument;

class Firestore {
    /**
     * Firestoreの接続用クライアント
     *
     * @var FirestoreClient
     */
    private readonly FirestoreClient $_client;

    /**
     * コレクション名
     *
     * @var string
     */
    private const _COLLECTION_NAME = 'feed';

    /**
     * Firestoreのキャッシュ用Document
     *
     * @var array
     */
    private array $cachedDocuments = [];

    public function __construct() {
        $this->_client = new FirestoreClient([
            'projectId' => getenv(App::ENV_PROJECT_ID),
        ]);
    }

    /**
     * FirestoreにDocumentを追加
     *
     * @param FeedDocument $feedDocument RSSから取得したFeedDocument
     * @return void
     */
    public function addDocument(FeedDocument $feedDocument) {
        $doc = $this->_getCollection()
            ->document($feedDocument->contentId);

        $doc->set($feedDocument->getArrayObject());
    }

    /**
     * FirestoreのDocumentを最新（15個）以外削除する
     *
     * @param array $feedContentIds RSSフィードから取得したContentID
     * @return void
     */
    public function deleteDocuments($feedContentIds) {
        foreach ($this->cachedDocuments as $doc) {
            // RSSフィードにないContentIDはもう使用しない古いContentIDなので削除する
            if (!in_array($doc['contentId'], $feedContentIds, true)) {
                $this->_client->document(self::_COLLECTION_NAME . '/' . $doc['contentId'])->delete();
            }
        }
    }

    /**
     * Firestoreに登録されていないContentIDを抽出する
     *
     * @param array $contentIds コンテンツIDの配列
     * @return array Firestoreに登録されていないContentIDの配列
     */
    public function getNewContentIds(array $contentIds):array {
        $col = $this->_getCollection();
        // キャッシュがあれば、そちらのDocumentsを見る
        $docs = count($this->cachedDocuments)
            ? $this->cachedDocuments
            : $col->documents();

        if (empty($docs)) {
            return $contentIds;
        }

        $docContentIds = [];
        foreach ($docs as $doc) {
            $docContentIds[] = $doc['contentId'];
        }

        $newContentIds = array_diff($contentIds, $docContentIds);
        $newContentIds = array_values($newContentIds);

        return $newContentIds;
    }

    /**
     * FirestoreのCollectionを取得する
     *
     * @return CollectionReference
     */
    private function _getCollection() {
        return $this->_client->collection(self::_COLLECTION_NAME);
    }
}