<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;

class CodeContextBuilder
{
    private const MAX_WINDOWS = 8;
    private const MAX_SNIPPETS = 16;
    private const MAX_LINES_PER_SNIPPET = 30;
    private const WINDOW_RADIUS = 6;

    private const GCS_OBJECTS = ['job.sh', 'vpngate_lib.sh'];

    private ?string $projectId;

    public function __construct(?string $projectId = null)
    {
        $this->projectId = $projectId;
    }

    public function build(AnalysisType $type, string $logText): array
    {
        $keywords = $this->extractKeywords($type, $logText);
        $snippets = [];

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            Log::warning('Could not get access token, skipping remote code context');
            return [
                'keywords' => $keywords,
                'snippetCount' => 0,
                'snippets' => [],
            ];
        }

        // GCSからシェルスクリプトを取得
        foreach (self::GCS_OBJECTS as $objectPath) {
            $content = $this->fetchFromGcs($objectPath, $accessToken);
            if ($content === null || $content === '') {
                continue;
            }

            $fileSnippets = $this->buildFileSnippets($content, $objectPath, $keywords);
            foreach ($fileSnippets as $snippet) {
                $snippets[] = $snippet;
                if (count($snippets) >= self::MAX_SNIPPETS) {
                    break 2;
                }
            }
        }

        // Workflows APIからワークフロー定義を取得
        if (count($snippets) < self::MAX_SNIPPETS) {
            $workflow = $this->fetchWorkflowDefinition($accessToken);
            if ($workflow !== null) {
                $fileSnippets = $this->buildFileSnippets($workflow['content'], $workflow['displayPath'], $keywords);
                foreach ($fileSnippets as $snippet) {
                    $snippets[] = $snippet;
                    if (count($snippets) >= self::MAX_SNIPPETS) {
                        break;
                    }
                }
            }
        }

        if (empty($snippets)) {
            Log::warning('No code context snippets were collected');
        }

        return [
            'keywords' => $keywords,
            'snippetCount' => count($snippets),
            'snippets' => $snippets,
        ];
    }

    private function extractKeywords(AnalysisType $type, string $logText): array
    {
        $common = [
            'error',
            'failed',
            'failure',
            'exception',
            'timeout',
            'retry',
            'batch',
            'yt-dlp',
            'mount',
            'network',
            '403',
            '429',
        ];

        $typeSpecific = $type === AnalysisType::TIMEOUT
            ? ['elapsed', 'polling', 'maxExecutionSeconds', 'sleep']
            : ['errorMessage', 'stack', 'denied', 'forbidden'];

        $dynamic = [];
        foreach ([
            'quota',
            'permission',
            'wasabi',
            'openvpn',
            'nfs',
            'dns',
            'memory',
            'oom',
        ] as $token) {
            if (stripos($logText, $token) !== false) {
                $dynamic[] = $token;
            }
        }

        $keywords = array_values(array_unique(array_merge($common, $typeSpecific, $dynamic)));
        return $keywords;
    }

    private function buildFileSnippets(string $content, string $relativePath, array $keywords): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        if (empty($lines)) {
            return [];
        }

        $matchIndexes = $this->findMatchIndexes($lines, $keywords);
        $windows = empty($matchIndexes)
            ? [[0, min(count($lines) - 1, self::MAX_LINES_PER_SNIPPET - 1)]]
            : $this->buildWindows($matchIndexes, count($lines));

        $snippets = [];
        foreach ($windows as [$start, $end]) {
            $length = $end - $start + 1;
            $slice = array_slice($lines, $start, $length);
            $text = implode("\n", $slice);
            $text = $this->maskSecrets($text);

            $snippets[] = [
                'path' => str_replace('\\', '/', $relativePath),
                'lineStart' => $start + 1,
                'lineEnd' => $end + 1,
                'snippet' => $text,
            ];

            if (count($snippets) >= 2) {
                break;
            }
        }

        return $snippets;
    }

    private function findMatchIndexes(array $lines, array $keywords): array
    {
        $indexes = [];
        foreach ($lines as $lineIndex => $line) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && stripos($line, $keyword) !== false) {
                    $indexes[] = $lineIndex;
                    break;
                }
            }
        }

        return $indexes;
    }

    private function buildWindows(array $matchIndexes, int $lineCount): array
    {
        $rawWindows = [];
        foreach ($matchIndexes as $index) {
            $start = max(0, $index - self::WINDOW_RADIUS);
            $end = min($lineCount - 1, $index + self::WINDOW_RADIUS);

            if (($end - $start + 1) > self::MAX_LINES_PER_SNIPPET) {
                $end = min($lineCount - 1, $start + self::MAX_LINES_PER_SNIPPET - 1);
            }

            $rawWindows[] = [$start, $end];
        }

        usort($rawWindows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($rawWindows as $window) {
            if (empty($merged)) {
                $merged[] = $window;
                continue;
            }

            $last = $merged[count($merged) - 1];
            if ($window[0] <= ($last[1] + 1)) {
                $merged[count($merged) - 1][1] = max($last[1], $window[1]);
                continue;
            }

            $merged[] = $window;
        }

        return array_slice($merged, 0, self::MAX_WINDOWS);
    }

    private function getAccessToken(): ?string
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
            Log::warning('Failed to get access token from metadata server: ' . $error);
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            Log::warning('No access_token in metadata server response');
            return null;
        }

        return $data['access_token'];
    }

    private function fetchFromGcs(string $objectPath, string $accessToken): ?string
    {
        $bucket = getenv('GCS_BUCKET_NAME') ?: null;
        if ($bucket === null) {
            Log::warning('GCS_BUCKET_NAME not set, skipping: ' . $objectPath);
            return null;
        }

        $url = sprintf(
            'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media',
            rawurlencode($bucket),
            rawurlencode($objectPath)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Log::warning(sprintf('GCS fetch failed for %s: HTTP %d %s', $objectPath, $httpCode, $error));
            return null;
        }

        return $response;
    }

    private function fetchWorkflowDefinition(string $accessToken): ?array
    {
        $workflowName = getenv('WORKFLOW_NAME') ?: null;
        $workflowRegion = getenv('WORKFLOW_REGION') ?: null;

        if ($workflowName === null || $workflowRegion === null || $this->projectId === null) {
            Log::warning('Workflow env vars or projectId missing, skipping workflow fetch');
            return null;
        }

        $url = sprintf(
            'https://workflows.googleapis.com/v1/projects/%s/locations/%s/workflows/%s',
            rawurlencode($this->projectId),
            rawurlencode($workflowRegion),
            rawurlencode($workflowName)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Log::warning(sprintf('Workflows API fetch failed: HTTP %d %s', $httpCode, $error));
            return null;
        }

        $data = json_decode($response, true);
        $content = $data['sourceContents'] ?? null;
        if ($content === null || $content === '') {
            return null;
        }

        return [
            'displayPath' => $workflowName . '.yaml',
            'content' => $content,
        ];
    }

    private function maskSecrets(string $text): string
    {
        $patterns = [
            '/(api[_-]?key\s*[:=]\s*[\'"])[^\'"]+([\'"])/i',
            '/(secret[_-]?key\s*[:=]\s*[\'"])[^\'"]+([\'"])/i',
            '/(token\s*[:=]\s*[\'"])[^\'"]+([\'"])/i',
            '/(password\s*[:=]\s*[\'"])[^\'"]+([\'"])/i',
        ];

        $masked = $text;
        foreach ($patterns as $pattern) {
            $masked = preg_replace($pattern, '$1***$2', $masked) ?? $masked;
        }

        return $masked;
    }
}
