<?php
namespace F122apg\YoutubeLiveChecker\GCP;

use F122apg\YoutubeLiveChecker\Log;

/**
 * Batch ジョブの情報を取得する。
 *
 * 1) ジョブ名 → UID の解決
 *    Workflow が扱うのはジョブ名 (job-live-download-1785661661)、
 *    Cloud Logging が持つのは UID (job-live-download-3d3e7ac1-0356-408d00) で、両者は別物。
 *    ログ検索には UID が要る。
 *
 * 2) 実行時スペックの取得
 *    machineType や bootDisk サイズはシェルスクリプトに現れないため、
 *    OOM (終了コード137) やディスク枯渇はログだけでは判定できない。
 *    Workflow のテンプレートではなく実ジョブから読むので、手動変更があっても実態と一致する。
 *
 * どちらも同じ jobs.get の結果を使うため、レスポンスはインスタンス内でキャッシュする。
 */
class BatchClient
{
    private const API_BASE = 'https://batch.googleapis.com/v1';
    private const TIMEOUT = 30;

    private bool $fetched = false;
    private ?array $job = null;

    public function __construct(
        private string $projectId,
        private string $region
    ) {
    }

    public function resolveUid(string $jobName): ?string
    {
        $job = $this->fetchJob($jobName);
        $uid = $job['uid'] ?? null;

        if (!is_string($uid) || $uid === '') {
            return null;
        }

        Log::info(sprintf('Resolved Batch job UID: %s -> %s', $jobName, $uid));
        return $uid;
    }

    /**
     * モデルに渡す実行時コンテキスト。
     * ログに現れない前提条件(メモリ量・ディスク容量・イメージ・タイムアウト)を補う。
     *
     * @return array<string, mixed>|null
     */
    public function fetchRuntimeContext(string $jobName): ?array
    {
        $job = $this->fetchJob($jobName);
        if ($job === null) {
            return null;
        }

        $taskSpec = $job['taskGroups'][0]['taskSpec'] ?? [];
        $policy = $job['allocationPolicy']['instances'][0]['policy'] ?? [];
        $network = $job['allocationPolicy']['network']['networkInterfaces'][0] ?? [];

        $statusEvents = [];
        foreach (($job['status']['statusEvents'] ?? []) as $event) {
            $description = trim((string)($event['description'] ?? ''));
            if ($description !== '') {
                $statusEvents[] = trim((string)($event['eventTime'] ?? '') . ' ' . $description);
            }
        }

        // ジョブ定義から拾うのは、ここに列挙した項目だけに限る (allowlist)。
        // ジョブ定義には将来 署名付きURLや資格情報が埋め込まれる可能性があり、
        // 「除外するものを選ぶ」方式だと取りこぼす。
        // script.text と secretVariables は診断価値がないので送らない。
        return [
            'state' => (string)($job['status']['state'] ?? 'UNKNOWN'),
            'statusEvents' => $statusEvents,
            'machineType' => (string)($policy['machineType'] ?? ''),
            'provisioningModel' => (string)($policy['provisioningModel'] ?? ''),
            'bootDisk' => [
                'type' => (string)($policy['bootDisk']['type'] ?? ''),
                'sizeGb' => (string)($policy['bootDisk']['sizeGb'] ?? ''),
                'image' => (string)($policy['bootDisk']['image'] ?? ''),
            ],
            'hasExternalIp' => !(bool)($network['noExternalIpAddress'] ?? false),
            'cpuMilli' => (string)($taskSpec['computeResource']['cpuMilli'] ?? ''),
            'memoryMib' => (string)($taskSpec['computeResource']['memoryMib'] ?? ''),
            'maxRunDurationSeconds' => (string)($taskSpec['maxRunDuration'] ?? ''),
            'notes' => [
                'e2-micro has 1 GB of memory. Exit code 137 usually means the OOM killer fired.',
                'cpuMilli and memoryMib are the Batch task request, not a container limit.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJob(string $jobName): ?array
    {
        if ($this->fetched) {
            return $this->job;
        }
        $this->fetched = true;

        $url = sprintf(
            '%s/projects/%s/locations/%s/jobs/%s',
            self::API_BASE,
            rawurlencode($this->projectId),
            rawurlencode($this->region),
            rawurlencode($jobName)
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
            // タイムアウト経路では Workflow がジョブを削除するため 404 になり得る。
            // その場合は Workflow から渡された jobUid だけが頼りになる
            Log::warning(sprintf(
                'Could not fetch Batch job "%s" (HTTP %d). It may already be deleted.',
                $jobName,
                $httpCode
            ));
            return null;
        }

        $data = json_decode((string)$response, true);
        $this->job = is_array($data) ? $data : null;

        return $this->job;
    }
}
