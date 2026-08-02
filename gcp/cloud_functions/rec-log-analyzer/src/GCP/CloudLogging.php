<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\Analysis\Sanitizer;

/**
 * Batch タスクログの取得。
 *
 * 検索キーの labels.job_uid には Batch のジョブ「名」ではなく「UID」が入る。
 * 名前を渡すとヒット0件になるため、呼び出し元は必ず UID を解決してから渡すこと。
 *
 * 取得したエントリは「まずサニタイズし、そのあとで」ノイズ除去・切り詰めを行う。
 * 逆順にすると秘匿情報の途中で切り詰めが起きたり、未処理の文字列が
 * 一時的にでも組み上がったりするため。OpenAI用とNotion用は同じ処理済み原文から作る。
 *
 * ノイズ除去は情報量のない行を落とすだけで、要約や抜粋はしない。
 * 12時間録画では yt-dlp の進捗行が数十MBに達するが、そこを削れば残りは丸ごと収まるため。
 */
class CloudLogging
{
    /**
     * Input Token Count API 自体へ極端に大きい入力を投げないための事前上限。
     * ここでは文字数からトークン数を推定せず、最終判定は OpenAI 側の正確な計数で行う。
     * 通常の大量反復ログはこの上限より前に collapseRepeatedPatterns() で数KBまで縮む。
     */
    private const DEFAULT_MAX_CHARS = 120000;

    /** この回数を超えて現れた同型行は集計へ回す */
    private const COLLAPSE_THRESHOLD = 5;

    /** 集計対象でも、時系列の文脈のためこの件数までは本文に残す */
    private const COLLAPSE_KEEP_SAMPLES = 3;
    private const HEAD_RATIO = 0.3;
    private const TIMEOUT = 60;

    /** yt-dlp のダウンロード進捗。完了行(100% / in) と Destination は残す */
    private const PROGRESS_PATTERN = '/^\[download\]\s+\d+(\.\d+)?%\s+of/';

    /** apt / dpkg の定型出力 */
    private const PACKAGE_NOISE_PATTERN =
        '/^(Get:|Hit:|Ign:|Selecting previously|Preparing to unpack|Unpacking |Setting up |Processing triggers|Reading (package lists|state information|database)|\(Reading database)/';

    /** ffmpeg の進捗 */
    private const FFMPEG_NOISE_PATTERN = '/^(frame|size)=\s*\d/';

    private int $maxChars;

    public function __construct(
        private string $projectId,
        private string $jobUid,
        private Sanitizer $sanitizer
    ) {
        $configured = (int)(getenv('ANALYZER_MAX_LOG_CHARS') ?: 0);
        $this->maxChars = $configured > 0 ? $configured : self::DEFAULT_MAX_CHARS;

        // Logging のフィルタ式へ文字列補間するため、書式を厳密に検証する。
        // 引用符を含む値を通すとフィルタ条件を書き換えられ、
        // プロジェクト全体のログが外部送信され得る
        if (preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $this->jobUid) !== 1) {
            throw new \InvalidArgumentException('jobUid contains characters that are not allowed.');
        }
    }

    /**
     * @return array{logText: string, llmLogText: string, entryCount: int, droppedNoiseLines: int, truncated: bool}
     */
    public function fetchLogBundle(): array
    {
        $entries = $this->fetchFromCloudLogging();

        // 何をするより先に秘匿化する。以降の処理は全て処理済みテキストに対して行う
        $entries = $this->sanitizer->sanitizeLines($entries);

        return $this->buildBundle($entries);
    }

    /**
     * @return array<int, string>
     */
    private function fetchFromCloudLogging(): array
    {
        $filter = sprintf(
            'logName = "projects/%s/logs/batch_task_logs" AND labels.job_uid = "%s"',
            $this->projectId,
            $this->jobUid
        );

        Log::info('Cloud Logging filter: ' . $filter);

        $entries = [];
        $pageToken = null;
        $pages = 0;

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

            $response = $this->request($body);

            foreach (($response['entries'] ?? []) as $entry) {
                $text = $this->extractEntryText($entry);
                if (trim($text) !== '') {
                    $entries[] = $text;
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
            $pages++;

            // 想定外に巨大なジョブで無限にページングしないための保険
            if ($pages >= 50) {
                Log::warning('Stopped paging Cloud Logging after 50 pages.');
                break;
            }
        } while ($pageToken !== null);

        Log::info(sprintf('Fetched %d log entries in %d page(s)', count($entries), $pages));
        return $entries;
    }

    /**
     * @param array<int, string> $entries サニタイズ済みであること
     * @return array{logText: string, llmLogText: string, entryCount: int, droppedNoiseLines: int, truncated: bool}
     */
    private function buildBundle(array $entries): array
    {
        if (empty($entries)) {
            Log::warning('No log entries found for job_uid: ' . $this->jobUid);
            return [
                'logText' => '',
                'llmLogText' => '',
                'entryCount' => 0,
                'droppedNoiseLines' => 0,
                'truncated' => false,
            ];
        }

        $logText = implode("\n", $entries);

        $filtered = $this->dropNoise($entries);
        $droppedNoiseLines = count($entries) - count($filtered);

        $deduped = $this->deduplicateConsecutive($filtered);
        $collapsed = $this->collapseRepeatedPatterns($deduped);
        $llmLogText = implode("\n", $collapsed);

        $truncated = false;
        if (mb_strlen($llmLogText) > $this->maxChars) {
            $llmLogText = $this->truncateKeepingBothEnds($llmLogText);
            $truncated = true;
        }

        Log::info(sprintf(
            'Log bundle: entries=%d, droppedNoise=%d, chars=%d, truncated=%s',
            count($entries),
            $droppedNoiseLines,
            mb_strlen($llmLogText),
            $truncated ? 'yes' : 'no'
        ));

        return [
            'logText' => $logText,
            'llmLogText' => $llmLogText,
            'entryCount' => count($entries),
            'droppedNoiseLines' => $droppedNoiseLines,
            'truncated' => $truncated,
        ];
    }

    /**
     * 情報量のない行を落とす。判断に使える行は落とさない。
     *
     * @param array<int, string> $entries
     * @return array<int, string>
     */
    private function dropNoise(array $entries): array
    {
        $result = [];

        foreach ($entries as $line) {
            $trimmed = ltrim($line);

            if (preg_match(self::PACKAGE_NOISE_PATTERN, $trimmed) === 1) {
                continue;
            }

            if (preg_match(self::FFMPEG_NOISE_PATTERN, $trimmed) === 1) {
                continue;
            }

            // 進捗行は落とすが、完了(100% / in 00:00:04)は残す
            if (preg_match(self::PROGRESS_PATTERN, $trimmed) === 1
                && !str_contains($trimmed, '100%')
                && !str_contains($trimmed, ' in ')
            ) {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param array<int, string> $entries
     * @return array<int, string>
     */
    private function deduplicateConsecutive(array $entries): array
    {
        $result = [];
        $previous = null;
        $repeat = 0;

        foreach ($entries as $line) {
            if ($line === $previous) {
                $repeat++;
                continue;
            }

            if ($repeat > 0) {
                $result[] = sprintf('... (直前の行が %d 回繰り返し)', $repeat + 1);
                $repeat = 0;
            }

            $result[] = $line;
            $previous = $line;
        }

        if ($repeat > 0) {
            $result[] = sprintf('... (直前の行が %d 回繰り返し)', $repeat + 1);
        }

        return $result;
    }

    /**
     * 数値だけが異なる同型の行をまとめる。
     *
     * 403ストームでは "Retrying fragment 1762 (7/10)" のような行が数千行続き、
     * 番号が違うため連続重複除去では潰れない。全体の9割近くを占めることもある。
     *
     * 各パターンの先頭数件を時系列の位置に残し、残りは末尾の集計へ回す。
     * 「何が何回起きたか」はむしろ集計の方が伝わるため、情報は失われない。
     *
     * @param array<int, string> $entries
     * @return array<int, string>
     */
    private function collapseRepeatedPatterns(array $entries): array
    {
        $counts = [];
        foreach ($entries as $line) {
            $key = $this->normalizeForGrouping($line);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = [];
        $emitted = [];
        $suppressed = [];

        foreach ($entries as $line) {
            $key = $this->normalizeForGrouping($line);

            if ($counts[$key] <= self::COLLAPSE_THRESHOLD) {
                $result[] = $line;
                continue;
            }

            $emitted[$key] = ($emitted[$key] ?? 0) + 1;
            if ($emitted[$key] <= self::COLLAPSE_KEEP_SAMPLES) {
                $result[] = $line;
                continue;
            }

            $suppressed[$key] = ($suppressed[$key] ?? 0) + 1;
        }

        if ($suppressed !== []) {
            arsort($suppressed);
            $result[] = '';
            $result[] = '=== 繰り返し行の集計（上記には各パターンの先頭'
                . self::COLLAPSE_KEEP_SAMPLES . '件のみ掲載） ===';
            foreach ($suppressed as $key => $count) {
                $result[] = sprintf('%d 回省略: %s', $count, $key);
            }
        }

        return $result;
    }

    /**
     * 同型の行を同一視するためのキーを作る。
     *
     * 未知の行に対して数値を一律に潰すと、空き容量 100GB と 0GB のような
     * 重大な差まで消える。実測で大量発生した既知の3形式だけを allowlist で
     * 正規化し、それ以外は行全体をそのままキーにする。
     */
    private function normalizeForGrouping(string $line): string
    {
        // HTTPステータスと最大試行回数は残し、対象番号と現在試行だけを潰す。
        if (preg_match('/\bRetrying fragment\s+\d+\s+\(\d+\/\d+\)/i', $line) === 1) {
            return preg_replace(
                '/(\bRetrying fragment)\s+\d+\s+\(\d+\/(\d+)\)/i',
                '$1 <FRAGMENT> (<ATTEMPT>/$2)',
                $line
            ) ?? $line;
        }

        // 欠落フラグメント。障害文言は残し、コンテンツ内の連番だけを潰す。
        if (preg_match('/\b(?:fragment\s+\d+\s+not found|Skipping fragment\s+\d+)/i', $line) === 1) {
            $normalized = preg_replace(
                ['/\bfragment\s+\d+\s+not found/i', '/\bSkipping fragment\s+\d+/i'],
                ['fragment <FRAGMENT> not found', 'Skipping fragment <FRAGMENT>'],
                $line
            );
            return $normalized ?? $line;
        }

        // googlevideo のCDNホスト差だけをまとめる。errno等の障害コードは行に残る。
        if (preg_match('/\b(?:[a-z0-9-]+\.)+googlevideo\.com\b/i', $line) === 1
            && preg_match('/\b(?:Failed to resolve|Errno)\b/i', $line) === 1
        ) {
            return preg_replace(
                '/\b(?:[a-z0-9-]+\.)+googlevideo\.com\b/i',
                '<GOOGLEVIDEO_HOST>',
                $line
            ) ?? $line;
        }

        return $line;
    }

    /**
     * 失敗の証拠は末尾に集まるため、末尾を厚く残す。
     */
    private function truncateKeepingBothEnds(string $text): string
    {
        $headChars = (int)($this->maxChars * self::HEAD_RATIO);
        $tailChars = $this->maxChars - $headChars;

        return mb_substr($text, 0, $headChars)
            . "\n\n... [中略: " . number_format(mb_strlen($text) - $this->maxChars) . " 文字を省略] ...\n\n"
            . mb_substr($text, -$tailChars);
    }

    private function extractEntryText(array $entry): string
    {
        if (isset($entry['textPayload'])) {
            return (string)$entry['textPayload'];
        }

        if (isset($entry['jsonPayload'])) {
            $payload = $entry['jsonPayload'];
            if (isset($payload['message']) && is_string($payload['message'])) {
                return $payload['message'];
            }
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return '';
    }

    private function request(array $body): array
    {
        $ch = curl_init('https://logging.googleapis.com/v2/entries:list');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . MetadataToken::get(),
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Cloud Logging request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf(
                'Cloud Logging returned HTTP %d: %s',
                $httpCode,
                mb_substr((string)$response, 0, 500)
            ));
        }

        return json_decode((string)$response, true) ?? [];
    }
}
