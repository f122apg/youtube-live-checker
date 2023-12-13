<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\App;
use Google\Cloud\Batch\V1\BatchServiceClient;

class BatchClient {
    /**
     * BatchのRegion
     *
     * @var string
     */
    public const ENV_BATCH_REGION = 'BATCH_REGION';

    /**
     * Firestoreの接続用クライアント
     *
     * @var BatchServiceClient
     */
    private readonly BatchServiceClient $_client;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->_client = new BatchServiceClient();
    }

    /**
     * 録画を開始しているコンテンツIDかチェックする
     *
     * @param string $contentId コンテンツID
     * @return bool true = 録画済み。false = 録画していない。
     */
    public function isRecordingLive(string $contentId): bool {
        $responseJobs = $this->_client->listJobs([
            'parent' => 'projects/' . getenv(App::ENV_PROJECT_ID) . '/locations/' . getenv(self::ENV_BATCH_REGION),
            'filter' => 'taskGroups.taskSpec.runnables.environment.variables.CONTENT_ID = "' . $contentId . '"',
        ]);

        return $responseJobs->getPage()->getResponseObject()->getJobs()->count() >= 1;
    }
}