<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\App;
use Google\Cloud\Workflows\Executions\V1beta\Execution;
use Google\Cloud\Workflows\Executions\V1beta\ExecutionsClient;

class Workflows {
    /**
     * Workflow名
     *
     * @var string
     */
    public const ENV_WORKFLOW_NAME = 'WORKFLOW_NAME';

    /**
     * Workflowsを実行する
     *
     * @param string $title 動画タイトル
     * @param string $contentId 動画ID
     * @return void
     */
    public static function execute(string $title, string $contentId) {
        $client = new ExecutionsClient();

        try {
            $formattedParent = $client->workflowName(App::ENV_PROJECT_ID, App::ENV_REGION, self::ENV_WORKFLOW_NAME);
            $execution = new Execution([
                'argument' => json_encode([
                    'title' => $title,
                    'contentId' => $contentId
                ])
            ]);
            $client->createExecution($formattedParent, $execution);
        } finally {
            $client->close();
        }
    }
}