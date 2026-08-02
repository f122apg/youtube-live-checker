<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\GCP\MetadataToken;

/**
 * モデルに渡す録画スクリプトを GCS から取得する。
 *
 * スクリプト3本で約35KB (1万トークン程度) しかないため、部分抽出はせず全文を渡す。
 * 抜粋すると行番号がずれ、モデルが実在しない位置を根拠として挙げるようになる。
 *
 * machineType やディスク容量といった実行時の前提はここには現れない。
 * それらは BatchClient::fetchRuntimeContext() が実ジョブから取得する。
 */
class CodeContextBuilder
{
    /** GCS バケット直下からの相対パス */
    private const GCS_OBJECTS = [
        'job.sh',
        'container/record_in_vpn.sh',
        'container/vpngate_lib_container.sh',
    ];

    private const TIMEOUT = 20;

    public function __construct(private Sanitizer $sanitizer)
    {
    }

    /**
     * @return array{files: array<int, array{path: string, content: string}>, fileCount: int}
     */
    public function build(): array
    {
        $bucket = getenv('GCS_BUCKET_NAME') ?: null;
        $files = [];

        if ($bucket === null) {
            Log::warning('GCS_BUCKET_NAME is not set. Skipping source code context.');
        } else {
            foreach (self::GCS_OBJECTS as $objectPath) {
                $content = $this->fetchFromGcs($bucket, $objectPath);
                if ($content === null) {
                    continue;
                }

                $files[] = [
                    'path' => $objectPath,
                    'content' => $this->sanitizer->sanitize($content),
                ];
            }
        }

        Log::info(sprintf('Code context: %d file(s)', count($files)));

        return [
            'files' => $files,
            'fileCount' => count($files),
        ];
    }

    private function fetchFromGcs(string $bucket, string $objectPath): ?string
    {
        $url = sprintf(
            'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media',
            rawurlencode($bucket),
            rawurlencode($objectPath)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MetadataToken::get()],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Log::warning(sprintf('Could not fetch gs://%s/%s (HTTP %d)', $bucket, $objectPath, $httpCode));
            return null;
        }

        return (string)$response;
    }

}
