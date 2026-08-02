<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\BatchClient;
use F122apg\YoutubeLiveChecker\GCP\CloudLogging;
use F122apg\YoutubeLiveChecker\AI\OpenAI;
use F122apg\YoutubeLiveChecker\Notion\Client as NotionClient;

class LogAnalyzer
{
    public function __construct(
        private string $jobId,
        private ?string $jobUid,
        private string $contentId,
        private string $title,
        private AnalysisType $type,
        private string $projectId,
        private string $batchRegion,
        private array $metadata,
        private ?string $modelOverride = null,
        private ?string $effortOverride = null
    ) {
    }

    /**
     * @return array{notionPageId: ?string, notionUrl: ?string, summary: string, classification: string, usage: array}
     */
    public function execute(): array
    {
        Log::info(sprintf(
            'Starting log analysis: type=%s, jobId=%s, jobUid=%s, contentId=%s',
            $this->type->value,
            $this->jobId,
            $this->jobUid ?? '(unresolved)',
            $this->contentId
        ));

        $batchClient = new BatchClient($this->projectId, $this->batchRegion);

        // Workflow から jobUid が渡っていればそれを使い、無ければ Batch API で解決する。
        // ジョブ名で Cloud Logging を検索しても常に0件になるため、ここが最重要。
        $jobUid = $this->jobUid;
        if ($jobUid === null || $jobUid === '') {
            $jobUid = $batchClient->resolveUid($this->jobId);
        }

        if ($jobUid === null || $jobUid === '') {
            Log::warning('Job UID could not be determined. Falling back to the job name, which will likely match nothing.');
            $jobUid = $this->jobId;
        }

        // ログにもスクリプトにも現れない実行時スペック (machineType / bootDisk など) を拾う。
        // jobs.get は BatchClient 内でキャッシュされるため追加のAPI呼び出しは発生しない
        $runtimeContext = $batchClient->fetchRuntimeContext($this->jobId);

        Log::info('Fetching logs from Cloud Logging...');

        // ログ・ソース・モデル出力で同じインスタンスを使う。
        // IPの仮名 ([IP_1] 等) を全体で一致させ、分析結果とログを突き合わせられるようにするため
        $sanitizer = new Sanitizer();

        $cloudLogging = new CloudLogging($this->projectId, $jobUid, $sanitizer);
        $logBundle = $cloudLogging->fetchLogBundle();

        // Notion へ保存するテキストもサニタイズ済みのものを使う。
        // OpenAI の設定に関わらず、外部へ出る点は同じであるため
        $logText = (string)($logBundle['logText'] ?? '');
        $llmLogText = (string)($logBundle['llmLogText'] ?? '');

        $usage = [];

        if (trim($llmLogText) === '') {
            Log::warning('No logs available. Producing a no-logs result without calling the model.');
            $result = AnalysisResult::noLogs($jobUid, (int)($logBundle['entryCount'] ?? 0));
        } else {
            Log::info('Building code context...');
            $codeContext = (new CodeContextBuilder($sanitizer))->build();

            $openAi = new OpenAI($sanitizer, $this->modelOverride, $this->effortOverride);
            $response = $openAi->analyze(
                $llmLogText,
                $this->jobId,
                $jobUid,
                $this->contentId,
                $this->title,
                $this->type,
                $this->metadata,
                $logBundle,
                $codeContext,
                $runtimeContext
            );

            $result = AnalysisResult::fromStructured($response['result']);
            $usage = $response['usage'];
        }

        $notionPageId = null;
        $notionUrl = null;

        try {
            Log::info('Saving analysis result to Notion...');
            $notionClient = new NotionClient();
            // Notion もサードパーティなので、ページ本文だけでなくプロパティも処理する。
            // 配信タイトルは配信者が自由に設定でき、任意の文字列が入り得る
            $notionPageId = $notionClient->createAnalysisPage(
                $sanitizer->sanitize($this->jobId),
                $sanitizer->sanitize($this->contentId),
                $sanitizer->sanitize($this->title),
                $this->type,
                $result->toMarkdown(),
                $logText,
                $sanitizer->sanitizeArray($this->metadata)
            );
            $notionUrl = 'https://www.notion.so/' . str_replace('-', '', $notionPageId);
        } catch (\Throwable $e) {
            // Notion への保存に失敗しても、通知に載せる要約は返せるようにする
            Log::error('Failed to save the analysis to Notion: ' . $e->getMessage());
        }

        Log::info(sprintf(
            'Analysis completed: classification=%s, confidence=%.2f, notionPage=%s',
            $result->classification(),
            $result->finalConfidence(),
            $notionPageId ?? 'none'
        ));

        return [
            'notionPageId' => $notionPageId,
            'notionUrl' => $notionUrl,
            'summary' => $result->notificationSummary(),
            'classification' => $result->classification(),
            'usage' => $usage,
        ];
    }
}
