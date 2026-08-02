<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

/**
 * OpenAI の構造化出力を包む値オブジェクト。
 *
 * strict スキーマで型は保証されているが、Notion への描画と通知用要約の取り出しを
 * 一箇所にまとめるために薄いラッパを置いている。
 */
class AnalysisResult
{
    private function __construct(private array $data)
    {
    }

    public static function fromStructured(array $data): self
    {
        return new self($data);
    }

    /**
     * ログが1件も取れなかった場合の結果。モデルは呼ばない。
     * ログ欠落そのものが調査対象なので、無難な文面で流さず原因候補を並べる。
     */
    public static function noLogs(string $jobUid, int $entryCount): self
    {
        return new self([
            'notification_summary' => 'ログを取得できなかったため原因を特定できませんでした。job_uid の指定かログ保持期間を確認してください。',
            'summary' => sprintf(
                'Cloud Logging に job_uid="%s" のエントリが1件も見つかりませんでした（取得件数 %d）。'
                . 'ジョブUIDの誤り、ログの保持期間切れ、または別プロジェクトへの出力が考えられます。',
                $jobUid,
                $entryCount
            ),
            'classification' => 'no_logs',
            'root_causes' => [],
            'actions' => [
                [
                    'priority' => 'high',
                    'owner' => 'ops',
                    'action' => 'labels.job_uid が Batch ジョブの UID（ジョブ名ではない）と一致しているか確認する',
                    'why' => 'ジョブ名で検索すると常に0件になる',
                ],
                [
                    'priority' => 'medium',
                    'owner' => 'ops',
                    'action' => 'Cloud Logging の保持期間（既定30日）を過ぎていないか確認する',
                    'why' => '古いジョブを手動再実行した場合はログが消えている',
                ],
            ],
            'missing_info' => ['当該ジョブの Cloud Logging エントリ'],
            'final_confidence' => 0.0,
        ]);
    }

    public function notificationSummary(): string
    {
        $summary = trim((string)($this->data['notification_summary'] ?? ''));
        return $summary !== '' ? $summary : '分析結果の要約を取得できませんでした。';
    }

    public function classification(): string
    {
        return (string)($this->data['classification'] ?? 'unknown');
    }

    public function finalConfidence(): float
    {
        $value = $this->data['final_confidence'] ?? 0;
        return is_numeric($value) ? max(0.0, min(1.0, (float)$value)) : 0.0;
    }

    /**
     * Notion のブロック分割は "### 見出し" 単位で行われるため、その形式で描画する。
     */
    public function toMarkdown(): string
    {
        $lines = [];

        $lines[] = '### 概要';
        $lines[] = trim((string)($this->data['summary'] ?? '')) ?: 'N/A';
        $lines[] = '';
        $lines[] = '分類: ' . $this->classification();
        $lines[] = '確度: ' . number_format($this->finalConfidence(), 2);
        $lines[] = '';

        $lines[] = '### 根本原因';
        $rootCauses = $this->data['root_causes'] ?? [];
        if (is_array($rootCauses) && $rootCauses !== []) {
            foreach ($rootCauses as $index => $cause) {
                if (!is_array($cause)) {
                    continue;
                }
                $confidence = is_numeric($cause['confidence'] ?? null) ? (float)$cause['confidence'] : 0.0;
                $lines[] = sprintf(
                    '%d) %s (確度 %s)',
                    $index + 1,
                    trim((string)($cause['cause'] ?? '')),
                    number_format($confidence, 2)
                );

                foreach ($this->stringList($cause['evidence_logs'] ?? []) as $evidence) {
                    $lines[] = '    根拠(ログ): ' . $evidence;
                }
                foreach ($this->stringList($cause['evidence_code'] ?? []) as $evidence) {
                    $lines[] = '    根拠(コード): ' . $evidence;
                }
                $lines[] = '';
            }
        } else {
            $lines[] = 'N/A';
            $lines[] = '';
        }

        $lines[] = '### 推奨アクション';
        $actions = $this->data['actions'] ?? [];
        if (is_array($actions) && $actions !== []) {
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $line = sprintf(
                    '[%s/%s] %s',
                    trim((string)($action['priority'] ?? 'medium')),
                    trim((string)($action['owner'] ?? 'ops')),
                    trim((string)($action['action'] ?? ''))
                );
                $why = trim((string)($action['why'] ?? ''));
                if ($why !== '') {
                    $line .= "\n    理由: " . $why;
                }
                $lines[] = $line;
            }
        } else {
            $lines[] = 'N/A';
        }
        $lines[] = '';

        $lines[] = '### 不足している情報';
        $missing = $this->stringList($this->data['missing_info'] ?? []);
        if ($missing !== []) {
            foreach ($missing as $item) {
                $lines[] = '- ' . $item;
            }
        } else {
            $lines[] = 'なし';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }
}
