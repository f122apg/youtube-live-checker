<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\CloudLogging;
use F122apg\YoutubeLiveChecker\AI\Gemini;
use F122apg\YoutubeLiveChecker\Notion\Client as NotionClient;

class LogAnalyzer
{
    public function __construct(
        private string $jobId,
        private string $contentId,
        private string $title,
        private AnalysisType $type,
        private string $projectId,
        private string $batchRegion,
        private array $metadata
    ) {
    }

    public function execute(): array
    {
        Log::info(sprintf(
            'Starting log analysis: type=%s, jobId=%s, contentId=%s',
            $this->type->value,
            $this->jobId,
            $this->contentId
        ));

        Log::info('Fetching logs from Cloud Logging...');
        $cloudLogging = new CloudLogging($this->projectId, $this->jobId);
        $logBundle = $cloudLogging->fetchLogBundle();

        $rawLogText = $logBundle['rawLogText'] ?? '';
        $llmLogText = $logBundle['llmLogText'] ?? '';

        Log::info('Building code context...');
        $codeContextBuilder = new CodeContextBuilder($this->projectId);
        $codeContext = $codeContextBuilder->build($this->type, $llmLogText);

        if (trim($llmLogText) === '') {
            Log::warning('No logs found, creating structured no-logs analysis');
            $analysisResult = $this->buildNoLogsResult($logBundle, $codeContext);
        } else {
            Log::info('Analyzing logs with Gemini...');
            $gemini = new Gemini();
            $analysisResult = $gemini->analyze(
                $llmLogText,
                $this->jobId,
                $this->contentId,
                $this->title,
                $this->type,
                $this->metadata,
                $logBundle,
                $codeContext
            );
        }

        Log::info('Saving analysis result to Notion...');
        $notionClient = new NotionClient();
        $pageId = $notionClient->createAnalysisPage(
            $this->jobId,
            $this->contentId,
            $this->title,
            $this->type,
            $analysisResult,
            $rawLogText,
            $this->metadata
        );

        Log::info('Log analysis completed successfully. Notion page: ' . $pageId);

        return [
            'notionPageId' => $pageId,
        ];
    }

    private function buildNoLogsResult(array $logBundle, array $codeContext): string
    {
        $summary = 'No Cloud Logging entries were found for the given job ID.';
        $entryCount = (int)($logBundle['entryCount'] ?? 0);
        $snippetCount = (int)($codeContext['snippetCount'] ?? 0);

        return implode("\n", [
            '### Analysis Summary',
            '- ' . $summary,
            '- Classification: no_logs',
            '- Final confidence: 0.000',
            '- Log entry count: ' . $entryCount,
            '- Code snippet count: ' . $snippetCount,
            '',
            '### Root Causes',
            '- N/A',
            '',
            '### Recommended Actions',
            '- [high/ops] Verify labels.job_uid matches the expected Batch job UID.',
            '- [medium/ops] Check whether logs exist in a different project or logName.',
            '',
            '### Missing Information',
            '- Any Cloud Logging entry for this job',
            '- Batch execution status reason from Workflows/Batch APIs',
        ]);
    }
}
