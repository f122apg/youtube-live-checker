<?php
namespace F122apg\YoutubeLiveChecker\AWS;

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;
use F122apg\YoutubeLiveChecker\Log;

class Sns {
    /**
     * SNSのリージョン
     *
     * @var string
     */
    private const _ENV_SNS_REGION = 'AWS_SNS_REGION';

    /**
     * SNSのTopic
     *
     * @var string
     */
    private const _ENV_SNS_TOPIC = 'AWS_SNS_TOPIC';

    /**
     * SNSの件名
     *
     * @var string
     */
    private string $_subject = '';

    /**
     * SNSの本文
     *
     * @var string
     */
    private string $_message = '';

    /**
     * 本文を作成する
     *
     * @param string $title 動画タイトル
     * @param string $contentId 動画ID
     * @return string
     */
    public function __construct(string $title, string $contentId) {
        $this->_subject = $this->_createSubject($title);
        $this->_message = $this->_createMessage($title, $contentId);
    }

    /**
     * SNSにメッセージをpublishする
     *
     * @param string $subject 件名
     * @param string $message 本文
     * @return void
     */
    public function publish() {
        $SnSclient = new SnsClient([
            'region' => getenv(self::_ENV_SNS_REGION),
            'version' => '2010-03-31',
        ]);

        try {
            $result = $SnSclient->publish([
                'Subject' => $this->_subject,
                'Message' => $this->_message,
                'TopicArn' => getenv(self::_ENV_SNS_TOPIC),
            ]);
            Log::info(var_export($result, true));
        } catch (AwsException $e) {
            Log::error(var_export($e->getMessage(), true));
        }
    }

    /**
     * 件名を作成する
     *
     * @param string $title 動画タイトル
     * @return string
     */
    private function _createSubject(string $title): string {
        return "[YoutubeLiveChecker] $$title の録画を開始しました";
    }

    /**
     * 本文を作成する
     *
     * @param string $title 動画タイトル
     * @param string $contentId 動画ID
     * @return string
     */
    private function _createMessage(string $title, string $contentId): string {
        return "動画タイトル：$title\n動画ID：$contentId\nの録画を開始しました。";
    }
}