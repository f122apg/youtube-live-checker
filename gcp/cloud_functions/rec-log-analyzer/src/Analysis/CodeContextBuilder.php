<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;

class CodeContextBuilder
{
    private const MAX_WINDOWS = 8;
    private const MAX_SNIPPETS = 16;
    private const MAX_LINES_PER_SNIPPET = 30;
    private const WINDOW_RADIUS = 6;

    private const FILE_ALLOWLIST = [
        'index.php',
        'src/Analysis/LogAnalyzer.php',
        'src/GCP/CloudLogging.php',
        'src/AI/Gemini.php',
        'src/Notion/Client.php',
        '../../workflows/tmpl_batch-workflows.yaml',
        '../../bucket/job.sh',
        '../../bucket/vpngate_lib.sh',
    ];

    private string $componentRoot;

    public function __construct(?string $componentRoot = null)
    {
        // src/Analysis -> src -> rec-log-analyzer
        $this->componentRoot = $componentRoot ?? dirname(__DIR__, 2);
    }

    public function build(AnalysisType $type, string $logText): array
    {
        $keywords = $this->extractKeywords($type, $logText);
        $snippets = [];

        foreach (self::FILE_ALLOWLIST as $relativePath) {
            $absolutePath = realpath($this->componentRoot . DIRECTORY_SEPARATOR . $relativePath);
            if ($absolutePath === false || !is_file($absolutePath)) {
                continue;
            }

            $content = @file_get_contents($absolutePath);
            if ($content === false || $content === '') {
                continue;
            }

            $fileSnippets = $this->buildFileSnippets(
                $content,
                $relativePath,
                $keywords
            );

            foreach ($fileSnippets as $snippet) {
                $snippets[] = $snippet;
                if (count($snippets) >= self::MAX_SNIPPETS) {
                    break 2;
                }
            }

            if (count($snippets) >= self::MAX_SNIPPETS) {
                break;
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
            'notion',
            'gemini',
            'cloud logging',
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
