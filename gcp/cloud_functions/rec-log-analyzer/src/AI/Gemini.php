<?php
namespace F122apg\YoutubeLiveChecker\AI;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\Analysis\AnalysisType;

class Gemini
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-2.5-pro';
    private const TEMPERATURE = 0.2;
    private const MAX_OUTPUT_TOKENS = 4096;
    private const TIMEOUT = 120;
    private const MAX_SCHEMA_RETRIES = 1;

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = getenv('GEMINI_API_KEY');
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY environment variable is not set');
        }

        $this->model = getenv('GEMINI_MODEL') ?: self::DEFAULT_MODEL;
    }

    public function analyze(
        string $logText,
        string $jobId,
        string $contentId,
        string $title,
        AnalysisType $type,
        array $metadata,
        array $logBundle = [],
        array $codeContext = []
    ): string {
        $baseParts = $this->buildUserParts(
            $logText,
            $jobId,
            $contentId,
            $title,
            $type,
            $metadata,
            $logBundle,
            $codeContext
        );

        $requestBody = $this->buildRequestBody($baseParts);
        $responseText = $this->request($requestBody);
        $structured = $this->parseStructuredResponse($responseText);

        if ($structured === null) {
            Log::warning('Gemini returned non-JSON or schema-invalid response. Retrying once...');
        }

        for ($attempt = 0; $structured === null && $attempt < self::MAX_SCHEMA_RETRIES; $attempt++) {
            $retryParts = $baseParts;
            $retryParts[] = [
                'text' => 'Return only valid JSON that matches the requested schema exactly. Do not include markdown fences.',
            ];
            $retryBody = $this->buildRequestBody($retryParts);
            $retryText = $this->request($retryBody);
            $structured = $this->parseStructuredResponse($retryText);
        }

        if ($structured === null) {
            $fallback = [
                'summary' => 'Gemini returned an invalid structured response.',
                'classification' => 'unknown',
                'root_causes' => [],
                'actions' => [
                    [
                        'priority' => 'high',
                        'owner' => 'ops',
                        'action' => 'Review raw logs and retry analysis.',
                        'why' => 'Structured response parsing failed.',
                    ],
                ],
                'missing_info' => ['Valid structured output from Gemini'],
                'final_confidence' => 0.0,
                'analysis_error' => 'schema_validation_failed',
            ];
            return $this->renderMarkdown($fallback);
        }

        Log::info('Gemini analysis completed successfully with structured JSON output');
        return $this->renderMarkdown($structured);
    }

    private function buildRequestBody(array $parts): array
    {
        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->buildSystemInstruction()],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => self::TEMPERATURE,
                'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->responseJsonSchema(),
            ],
        ];
    }

    private function buildUserParts(
        string $logText,
        string $jobId,
        string $contentId,
        string $title,
        AnalysisType $type,
        array $metadata,
        array $logBundle,
        array $codeContext
    ): array {
        $incidentContext = [
            'analysisType' => $type->value,
            'jobId' => $jobId,
            'contentId' => $contentId,
            'title' => $title,
            'metadata' => $metadata,
        ];

        return [
            [
                'text' => "Incident context (JSON)\n" . $this->encodeJson($incidentContext),
            ],
            [
                'text' => "Preprocessed logs\n```\n{$logText}\n```",
            ],
            [
                'text' => "Log bundle (JSON)\n" . $this->encodeJson($logBundle),
            ],
            [
                'text' => "Code context snippets (JSON)\n" . $this->encodeJson($codeContext),
            ],
        ];
    }

    private function buildSystemInstruction(): string
    {
        return implode("\n", [
            'You are an incident analysis assistant for a YouTube live recording pipeline.',
            'Use logs as the primary evidence and code snippets as supporting context.',
            'Do not claim certainty without evidence.',
            'When evidence is weak, include it in missing_info and lower confidence.',
            'Return only JSON that matches the provided schema.',
            'For each root cause, include concrete evidence_log lines and evidence_code references.',
            'Confidence values must be between 0 and 1.',
        ]);
    }

    private function responseJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'classification' => [
                    'type' => 'string',
                    'enum' => ['failure', 'timeout', 'no_logs', 'unknown'],
                ],
                'root_causes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cause' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'evidence_logs' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'evidence_code' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['cause', 'confidence', 'evidence_logs', 'evidence_code'],
                    ],
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'priority' => ['type' => 'string'],
                            'owner' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                        ],
                        'required' => ['priority', 'owner', 'action', 'why'],
                    ],
                ],
                'missing_info' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'final_confidence' => ['type' => 'number'],
                'analysis_error' => ['type' => 'string'],
            ],
            'required' => [
                'summary',
                'classification',
                'root_causes',
                'actions',
                'missing_info',
                'final_confidence',
            ],
        ];
    }

    private function request(array $requestBody): string
    {
        $url = self::API_BASE_URL . $this->model . ':generateContent';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Gemini API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf(
                'Gemini API returned HTTP %d: %s',
                $httpCode,
                $response
            ));
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null) {
            throw new \RuntimeException('Gemini API returned no text content: ' . $response);
        }

        return $text;
    }

    private function parseStructuredResponse(string $responseText): ?array
    {
        $jsonText = trim($responseText);
        if (str_starts_with($jsonText, '```')) {
            $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $jsonText) ?? $jsonText;
            $jsonText = trim($jsonText);
        }

        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->normalizeStructuredResponse($decoded);
    }

    private function normalizeStructuredResponse(array $decoded): array
    {
        $classification = (string)($decoded['classification'] ?? 'unknown');
        if (!in_array($classification, ['failure', 'timeout', 'no_logs', 'unknown'], true)) {
            $classification = 'unknown';
        }

        $rootCauses = [];
        foreach (($decoded['root_causes'] ?? []) as $cause) {
            if (!is_array($cause)) {
                continue;
            }
            $rootCauses[] = [
                'cause' => (string)($cause['cause'] ?? ''),
                'confidence' => $this->normalizeConfidence($cause['confidence'] ?? 0),
                'evidence_logs' => $this->normalizeStringList($cause['evidence_logs'] ?? []),
                'evidence_code' => $this->normalizeStringList($cause['evidence_code'] ?? []),
            ];
        }

        $actions = [];
        foreach (($decoded['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $actions[] = [
                'priority' => (string)($action['priority'] ?? 'medium'),
                'owner' => (string)($action['owner'] ?? 'ops'),
                'action' => (string)($action['action'] ?? ''),
                'why' => (string)($action['why'] ?? ''),
            ];
        }

        return [
            'summary' => (string)($decoded['summary'] ?? ''),
            'classification' => $classification,
            'root_causes' => $rootCauses,
            'actions' => $actions,
            'missing_info' => $this->normalizeStringList($decoded['missing_info'] ?? []),
            'final_confidence' => $this->normalizeConfidence($decoded['final_confidence'] ?? 0),
            'analysis_error' => isset($decoded['analysis_error']) ? (string)$decoded['analysis_error'] : null,
        ];
    }

    private function normalizeStringList(mixed $value): array
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

    private function normalizeConfidence(mixed $value): float
    {
        $number = is_numeric($value) ? (float)$value : 0.0;
        if ($number < 0) {
            return 0.0;
        }
        if ($number > 1) {
            return 1.0;
        }
        return round($number, 3);
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function renderMarkdown(array $result): string
    {
        $lines = [];

        $summary = trim((string)($result['summary'] ?? ''));
        $classification = (string)($result['classification'] ?? 'unknown');
        $finalConfidence = $this->normalizeConfidence($result['final_confidence'] ?? 0);

        $lines[] = '### Analysis Summary';
        $lines[] = '- ' . ($summary !== '' ? $summary : 'N/A');
        $lines[] = '- Classification: ' . $classification;
        $lines[] = '- Final confidence: ' . number_format($finalConfidence, 3);
        $lines[] = '';

        $lines[] = '### Root Causes';
        $rootCauses = $result['root_causes'] ?? [];
        if (is_array($rootCauses) && !empty($rootCauses)) {
            foreach ($rootCauses as $index => $cause) {
                if (!is_array($cause)) {
                    continue;
                }
                $causeText = trim((string)($cause['cause'] ?? ''));
                $confidence = $this->normalizeConfidence($cause['confidence'] ?? 0);
                $lines[] = sprintf('- %d) %s (confidence: %s)', $index + 1, $causeText, number_format($confidence, 3));

                $evidenceLogs = $this->normalizeStringList($cause['evidence_logs'] ?? []);
                if (!empty($evidenceLogs)) {
                    $lines[] = '- evidence_logs: ' . implode(' | ', $evidenceLogs);
                }

                $evidenceCode = $this->normalizeStringList($cause['evidence_code'] ?? []);
                if (!empty($evidenceCode)) {
                    $lines[] = '- evidence_code: ' . implode(' | ', $evidenceCode);
                }
            }
        } else {
            $lines[] = '- N/A';
        }
        $lines[] = '';

        $lines[] = '### Recommended Actions';
        $actions = $result['actions'] ?? [];
        if (is_array($actions) && !empty($actions)) {
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $priority = trim((string)($action['priority'] ?? 'medium'));
                $owner = trim((string)($action['owner'] ?? 'ops'));
                $actionText = trim((string)($action['action'] ?? ''));
                $why = trim((string)($action['why'] ?? ''));
                $line = '- [' . $priority . '/' . $owner . '] ' . $actionText;
                if ($why !== '') {
                    $line .= ' (why: ' . $why . ')';
                }
                $lines[] = $line;
            }
        } else {
            $lines[] = '- N/A';
        }
        $lines[] = '';

        $lines[] = '### Missing Information';
        $missingInfo = $this->normalizeStringList($result['missing_info'] ?? []);
        if (!empty($missingInfo)) {
            foreach ($missingInfo as $item) {
                $lines[] = '- ' . $item;
            }
        } else {
            $lines[] = '- N/A';
        }

        $analysisError = trim((string)($result['analysis_error'] ?? ''));
        if ($analysisError !== '') {
            $lines[] = '';
            $lines[] = '### Analysis Error';
            $lines[] = '- ' . $analysisError;
        }

        return implode("\n", $lines);
    }
}
