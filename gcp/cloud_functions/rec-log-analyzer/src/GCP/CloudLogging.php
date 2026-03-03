<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\Log;

class CloudLogging
{
    private const API_URL = 'https://logging.googleapis.com/v2/entries:list';
    private const MAX_LOG_LENGTH = 100000;
    private const HEAD_RATIO = 0.3;
    private const MAX_TIMELINE_LINES = 160;
    private const MAX_TAIL_LINES = 160;
    private const MAX_ERROR_WINDOW_LINES = 240;
    private const ERROR_WINDOW_RADIUS = 3;
    private const ERROR_PATTERN = '/\b(error|failed|failure|timeout|exception|traceback|quota|429|403|forbidden|denied|mount|network|unavailable|oom)\b/i';

    public function __construct(
        private string $projectId,
        private string $jobId
    ) {
    }

    public function fetchLogs(): string
    {
        $bundle = $this->fetchLogBundle();
        return $bundle['rawLogText'];
    }

    public function fetchLogBundle(): array
    {
        $accessToken = $this->getAccessToken();
        $filter = sprintf(
            'logName = "projects/%s/logs/batch_task_logs" AND labels.job_uid = "%s"',
            $this->projectId,
            $this->jobId
        );

        $allEntries = [];
        $pageToken = null;

        do {
            $body = [
                'resourceNames' => ['projects/' . $this->projectId],
                'filter' => $filter,
                'orderBy' => 'timestamp asc',
                'pageSize' => 1000,
            ];

            if ($pageToken !== null) {
                $body['pageToken'] = $pageToken;
            }

            $response = $this->request($accessToken, $body);

            if (isset($response['entries'])) {
                foreach ($response['entries'] as $entry) {
                    $allEntries[] = $this->extractLogText($entry);
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        if (empty($allEntries)) {
            Log::warning('No log entries found for job: ' . $this->jobId);
            return [
                'rawLogText' => '',
                'llmLogText' => '',
                'timelineDigest' => '',
                'errorWindows' => '',
                'tailExcerpt' => '',
                'entryCount' => 0,
                'normalizedEntryCount' => 0,
            ];
        }

        $rawLogText = implode("\n", $allEntries);
        $normalizedEntries = $this->deduplicateConsecutiveEntries($allEntries);

        $timelineDigest = $this->buildTimelineDigest($normalizedEntries);
        $errorWindows = $this->buildErrorWindows($normalizedEntries);
        $tailExcerpt = $this->buildTailExcerpt($normalizedEntries);

        $llmSections = [];
        if ($timelineDigest !== '') {
            $llmSections[] = "[timeline_digest]\n" . $timelineDigest;
        }
        if ($errorWindows !== '') {
            $llmSections[] = "[error_windows]\n" . $errorWindows;
        }
        if ($tailExcerpt !== '') {
            $llmSections[] = "[tail_excerpt]\n" . $tailExcerpt;
        }

        $llmLogText = implode("\n\n", $llmSections);

        Log::info(sprintf(
            'Fetched %d log entries (%d chars), normalized=%d',
            count($allEntries),
            strlen($rawLogText),
            count($normalizedEntries)
        ));

        return [
            'rawLogText' => $this->truncateText($rawLogText, self::MAX_LOG_LENGTH),
            'llmLogText' => $this->truncateText($llmLogText, self::MAX_LOG_LENGTH),
            'timelineDigest' => $this->truncateText($timelineDigest, self::MAX_LOG_LENGTH),
            'errorWindows' => $this->truncateText($errorWindows, self::MAX_LOG_LENGTH),
            'tailExcerpt' => $this->truncateText($tailExcerpt, self::MAX_LOG_LENGTH),
            'entryCount' => count($allEntries),
            'normalizedEntryCount' => count($normalizedEntries),
        ];
    }

    private function extractLogText(array $entry): string
    {
        $timestamp = $entry['timestamp'] ?? '';
        $message = $entry['textPayload']
            ?? $entry['jsonPayload']['message']
            ?? json_encode($entry['jsonPayload'] ?? $entry['protoPayload'] ?? '');

        if (is_array($message)) {
            $message = json_encode($message);
        }

        return sprintf('[%s] %s', $timestamp, (string)$message);
    }

    private function deduplicateConsecutiveEntries(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $result = [];
        $previous = null;
        $repeatCount = 0;

        foreach ($entries as $entry) {
            if ($entry === $previous) {
                $repeatCount++;
                continue;
            }

            if ($previous !== null) {
                $result[] = $previous;
                if ($repeatCount > 0) {
                    $result[] = sprintf('[repeat] previous line repeated %d times', $repeatCount);
                }
            }

            $previous = $entry;
            $repeatCount = 0;
        }

        if ($previous !== null) {
            $result[] = $previous;
            if ($repeatCount > 0) {
                $result[] = sprintf('[repeat] previous line repeated %d times', $repeatCount);
            }
        }

        return $result;
    }

    private function buildTimelineDigest(array $entries): string
    {
        if (empty($entries)) {
            return '';
        }

        if (count($entries) <= self::MAX_TIMELINE_LINES) {
            return implode("\n", $entries);
        }

        $headCount = intdiv(self::MAX_TIMELINE_LINES, 2);
        $tailCount = self::MAX_TIMELINE_LINES - $headCount;

        $head = array_slice($entries, 0, $headCount);
        $tail = array_slice($entries, -$tailCount);
        $skipped = count($entries) - count($head) - count($tail);

        return implode("\n", $head)
            . "\n[omitted] " . number_format($skipped) . " lines omitted\n"
            . implode("\n", $tail);
    }

    private function buildErrorWindows(array $entries): string
    {
        if (empty($entries)) {
            return '';
        }

        $matchIndexes = [];
        foreach ($entries as $index => $entry) {
            if (preg_match(self::ERROR_PATTERN, $entry) === 1) {
                $matchIndexes[] = $index;
            }
        }

        if (empty($matchIndexes)) {
            return '';
        }

        $windows = $this->mergeWindows($matchIndexes, count($entries));
        $collected = [];
        $lineCount = 0;

        foreach ($windows as [$start, $end]) {
            $windowLines = array_slice($entries, $start, $end - $start + 1);
            if (!empty($collected)) {
                $collected[] = '[window-break]';
                $lineCount++;
            }
            foreach ($windowLines as $line) {
                $collected[] = $line;
                $lineCount++;
                if ($lineCount >= self::MAX_ERROR_WINDOW_LINES) {
                    break 2;
                }
            }
        }

        return implode("\n", $collected);
    }

    private function mergeWindows(array $matchIndexes, int $lineCount): array
    {
        $windows = [];
        foreach ($matchIndexes as $index) {
            $windows[] = [
                max(0, $index - self::ERROR_WINDOW_RADIUS),
                min($lineCount - 1, $index + self::ERROR_WINDOW_RADIUS),
            ];
        }

        usort($windows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($windows as $window) {
            if (empty($merged)) {
                $merged[] = $window;
                continue;
            }

            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];
            if ($window[0] <= ($last[1] + 1)) {
                $merged[$lastIndex][1] = max($last[1], $window[1]);
                continue;
            }

            $merged[] = $window;
        }

        return $merged;
    }

    private function buildTailExcerpt(array $entries): string
    {
        if (empty($entries)) {
            return '';
        }

        return implode("\n", array_slice($entries, -self::MAX_TAIL_LINES));
    }

    private function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $headLength = (int)($maxLength * self::HEAD_RATIO);
        $tailLength = $maxLength - $headLength;

        $head = mb_substr($text, 0, $headLength);
        $tail = mb_substr($text, -$tailLength);
        $truncatedChars = mb_strlen($text) - $maxLength;

        return $head
            . "\n\n... [truncated: " . number_format($truncatedChars) . " chars] ...\n\n"
            . $tail;
    }

    private function request(string $accessToken, array $body): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Cloud Logging API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf(
                'Cloud Logging API returned HTTP %d: %s',
                $httpCode,
                $response
            ));
        }

        return json_decode($response, true) ?? [];
    }

    private function getAccessToken(): string
    {
        $metadataUrl = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';
        $ch = curl_init($metadataUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to get access token from metadata server: ' . $error);
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('No access_token in metadata server response');
        }

        return $data['access_token'];
    }
}
