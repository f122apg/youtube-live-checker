<?php
namespace F122apg\YoutubeLiveChecker\AWS;

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;
use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\Notification\NotificationType;

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
     * Youtubeの動画URL
     *
     * @var string
     */
    private const _YOUTUBE_VIDEO_URL = 'https://www.youtube.com/watch?v=%s';

    /**
     * コンストラクタ
     *
     * @param string $title 動画タイトル
     * @param string $contentId 動画ID
     * @param NotificationType $type 通知タイプ（デフォルト: START）
     * @param array $metadata 追加メタデータ（jobId, retryCount, maxRetries, elapsedHours, errorMessage）
     */
    public function __construct(
        string $title,
        string $contentId,
        NotificationType $type = NotificationType::START,
        array $metadata = []
    ) {
        $this->_subject = $this->_createSubject($title, $type);
        $this->_message = $this->_createMessage($title, $contentId, $type, $metadata);
    }

    /**
     * SNSにメッセージをpublishする
     *
     * @return void
     */
    public function publish(): void {
        $SnSclient = new SnsClient([
            'region' => getenv(self::_ENV_SNS_REGION),
            'version' => '2010-03-31',
        ]);

        Log::info("Generated Subject: " . $this->_subject);
        Log::info("Subject length: " . mb_strlen($this->_subject));
        Log::info("Subject bytes: " . bin2hex($this->_subject));

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
     * @param NotificationType $type 通知タイプ
     * @return string
     */
    private function _createSubject(string $title, NotificationType $type): string {
        $prefix = "[YoutubeLiveChecker]";
        $maxLength = 95; // AWS SNS制限100文字に対して安全係数5

        // タイプに応じたプレフィックスとサフィックスを決定
        [$typePrefix, $suffix] = match($type) {
            NotificationType::START => ["", " の録画を開始しました"],
            NotificationType::SUCCESS => ["", " の録画が完了しました"],
            NotificationType::FAILURE => [" [失敗]", " の録画が失敗しました"],
            NotificationType::TIMEOUT => [" [タイムアウト]", " の録画がタイムアウトしました"],
            NotificationType::PROGRESS => [" [進行中]", " はまだ録画中です"],
            NotificationType::RETRY => [" [リトライ]", " の録画をリトライします"],
        };

        // タイトルの最大長を計算
        $fixedLength = mb_strlen($prefix . $typePrefix . " " . $suffix);
        $maxTitleLength = $maxLength - $fixedLength;

        // タイトルを切り詰める
        $truncatedTitle = $title;
        if (mb_strlen($title) > $maxTitleLength) {
            $truncatedTitle = mb_substr($title, 0, $maxTitleLength - 3) . "...";
        }

        return $prefix . $typePrefix . " " . $truncatedTitle . $suffix;
    }

    /**
     * 本文を作成する
     *
     * @param string $title 動画タイトル
     * @param string $contentId 動画ID
     * @param NotificationType $type 通知タイプ
     * @param array $metadata 追加メタデータ
     * @return string
     */
    private function _createMessage(
        string $title,
        string $contentId,
        NotificationType $type,
        array $metadata
    ): string {
        $url = sprintf(self::_YOUTUBE_VIDEO_URL, $contentId);
        $baseInfo = "動画タイトル: {$title}\n動画ID: {$contentId}\nURL: {$url}";

        $jobInfo = "";
        if (!empty($metadata['jobId'])) {
            $jobInfo = "\nジョブID: {$metadata['jobId']}";
        }

        return match($type) {
            NotificationType::START => "{$baseInfo}\n\n録画を開始しました。",
            NotificationType::SUCCESS => "{$baseInfo}{$jobInfo}\n\n録画が正常に完了しました。",
            NotificationType::FAILURE => $this->_createFailureMessage($baseInfo, $jobInfo, $metadata),
            NotificationType::TIMEOUT => $this->_createTimeoutMessage($baseInfo, $jobInfo, $metadata),
            NotificationType::PROGRESS => $this->_createProgressMessage($baseInfo, $jobInfo, $metadata),
            NotificationType::RETRY => $this->_createRetryMessage($baseInfo, $jobInfo, $metadata),
        };
    }

    /**
     * 失敗時のメッセージを作成する
     */
    private function _createFailureMessage(string $baseInfo, string $jobInfo, array $metadata): string {
        $retryInfo = "";
        if (!empty($metadata['retryCount'])) {
            $retryInfo = "\nリトライ回数: {$metadata['retryCount']}回";
        }

        $errorInfo = "";
        if (!empty($metadata['errorMessage'])) {
            $errorInfo = "\nエラー詳細: {$metadata['errorMessage']}";
        }

        return "{$baseInfo}{$jobInfo}{$retryInfo}{$errorInfo}\n\n録画が失敗しました。\n詳細はCloud Loggingを確認してください。";
    }

    /**
     * タイムアウト時のメッセージを作成する
     */
    private function _createTimeoutMessage(string $baseInfo, string $jobInfo, array $metadata): string {
        $elapsedInfo = "";
        if (!empty($metadata['elapsedHours'])) {
            $elapsedInfo = "\n経過時間: {$metadata['elapsedHours']}時間";
        }

        return "{$baseInfo}{$jobInfo}{$elapsedInfo}\n\n録画が最大実行時間を超過しました。\nジョブがハングしている可能性があります。\n詳細はCloud Loggingを確認してください。";
    }

    /**
     * 進行中のメッセージを作成する
     */
    private function _createProgressMessage(string $baseInfo, string $jobInfo, array $metadata): string {
        $elapsedInfo = "";
        if (!empty($metadata['elapsedHours'])) {
            $elapsedInfo = "\n経過時間: {$metadata['elapsedHours']}時間";
        }

        return "{$baseInfo}{$jobInfo}{$elapsedInfo}\n\n録画はまだ実行中です。\n長時間の配信を録画している可能性があります。";
    }

    /**
     * リトライ時のメッセージを作成する
     */
    private function _createRetryMessage(string $baseInfo, string $jobInfo, array $metadata): string {
        $retryInfo = "";
        if (!empty($metadata['retryCount']) && !empty($metadata['maxRetries'])) {
            $retryInfo = "\nリトライ: {$metadata['retryCount']}/{$metadata['maxRetries']}回目";
        }

        return "{$baseInfo}{$jobInfo}{$retryInfo}\n\n前回の録画が失敗したため、リトライを実行します。";
    }
}
