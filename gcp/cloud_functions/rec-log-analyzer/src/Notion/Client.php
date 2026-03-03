<?php
namespace F122apg\YoutubeLiveChecker\Notion;

use F122apg\YoutubeLiveChecker\Log;
use F122apg\YoutubeLiveChecker\Analysis\AnalysisType;

class Client
{
    private const API_URL = 'https://api.notion.com/v1';
    private const API_VERSION = '2022-06-28';
    private const MAX_BLOCK_TEXT_LENGTH = 2000;
    private const LOG_EXCERPT_LENGTH = 5000;

    private string $apiKey;
    private string $databaseId;

    public function __construct()
    {
        $this->apiKey = getenv('NOTION_API_KEY');
        if (empty($this->apiKey)) {
            throw new \RuntimeException('NOTION_API_KEY environment variable is not set');
        }

        $this->databaseId = getenv('NOTION_DATABASE_ID');
        if (empty($this->databaseId)) {
            throw new \RuntimeException('NOTION_DATABASE_ID environment variable is not set');
        }
    }

    public function createAnalysisPage(
        string $jobId,
        string $contentId,
        string $title,
        AnalysisType $type,
        string $analysisResult,
        string $logText,
        array $metadata
    ): string {
        $pageTitle = sprintf('[%s] %s', $type->label(), $title);

        $properties = $this->buildProperties(
            $pageTitle,
            $jobId,
            $contentId,
            $type,
            $metadata
        );

        $children = $this->buildBlocks($analysisResult, $logText);

        $body = [
            'parent' => ['database_id' => $this->databaseId],
            'properties' => $properties,
            'children' => $children,
        ];

        $response = $this->request('POST', '/pages', $body);
        $pageId = $response['id'] ?? null;

        if ($pageId === null) {
            throw new \RuntimeException('Notion API returned no page ID');
        }

        Log::info('Notion page created: ' . $pageId);
        return $pageId;
    }

    private function buildProperties(
        string $pageTitle,
        string $jobId,
        string $contentId,
        AnalysisType $type,
        array $metadata
    ): array {
        $properties = [
            'タイトル' => [
                'title' => [
                    ['text' => ['content' => $pageTitle]],
                ],
            ],
            'Job ID' => [
                'rich_text' => [
                    ['text' => ['content' => $jobId]],
                ],
            ],
            'Content ID' => [
                'rich_text' => [
                    ['text' => ['content' => $contentId]],
                ],
            ],
            'YouTube URL' => [
                'url' => 'https://www.youtube.com/watch?v=' . $contentId,
            ],
            '障害種別' => [
                'select' => ['name' => $type->label()],
            ],
            'ステータス' => [
                'select' => ['name' => '未対応'],
            ],
            '発生日時' => [
                'date' => ['start' => date('c')],
            ],
        ];

        if (!empty($metadata['retryCount'])) {
            $properties['リトライ回数'] = [
                'number' => (int)$metadata['retryCount'],
            ];
        }

        if (!empty($metadata['elapsedHours'])) {
            $properties['経過時間'] = [
                'rich_text' => [
                    ['text' => ['content' => $metadata['elapsedHours'] . '時間']],
                ],
            ];
        }

        return $properties;
    }

    private function buildBlocks(string $analysisResult, string $logText): array
    {
        $blocks = [];

        // 分析結果をセクションごとに分割
        $sections = $this->parseAnalysisSections($analysisResult);

        foreach ($sections as $section) {
            $blocks[] = [
                'object' => 'block',
                'type' => 'heading_3',
                'heading_3' => [
                    'rich_text' => [
                        ['text' => ['content' => $section['heading']]],
                    ],
                ],
            ];

            foreach ($this->splitText($section['content']) as $chunk) {
                $blocks[] = [
                    'object' => 'block',
                    'type' => 'paragraph',
                    'paragraph' => [
                        'rich_text' => [
                            ['text' => ['content' => $chunk]],
                        ],
                    ],
                ];
            }
        }

        // ログ抜粋（トグルブロック内にコードブロック）
        $logExcerpt = $this->getLogExcerpt($logText);
        if (!empty($logExcerpt)) {
            $codeBlocks = [];
            foreach ($this->splitText($logExcerpt) as $chunk) {
                $codeBlocks[] = [
                    'object' => 'block',
                    'type' => 'code',
                    'code' => [
                        'rich_text' => [
                            ['text' => ['content' => $chunk]],
                        ],
                        'language' => 'plain text',
                    ],
                ];
            }

            $blocks[] = [
                'object' => 'block',
                'type' => 'toggle',
                'toggle' => [
                    'rich_text' => [
                        ['text' => ['content' => 'ログ抜粋（末尾' . number_format(self::LOG_EXCERPT_LENGTH) . '文字）']],
                    ],
                    'children' => $codeBlocks,
                ],
            ];
        }

        return $blocks;
    }

    private function parseAnalysisSections(string $text): array
    {
        $sections = [];
        $lines = explode("\n", $text);
        $currentHeading = null;
        $currentContent = [];

        foreach ($lines as $line) {
            if (preg_match('/^###\s+(.+)$/', $line, $matches)) {
                if ($currentHeading !== null) {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'content' => trim(implode("\n", $currentContent)),
                    ];
                }
                $currentHeading = $matches[1];
                $currentContent = [];
            } else {
                $currentContent[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[] = [
                'heading' => $currentHeading,
                'content' => trim(implode("\n", $currentContent)),
            ];
        }

        // セクションが見つからない場合は全体を1つのセクションとして扱う
        if (empty($sections)) {
            $sections[] = [
                'heading' => '分析結果',
                'content' => trim($text),
            ];
        }

        return $sections;
    }

    private function splitText(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_BLOCK_TEXT_LENGTH) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunks[] = mb_substr($text, $offset, self::MAX_BLOCK_TEXT_LENGTH);
            $offset += self::MAX_BLOCK_TEXT_LENGTH;
        }

        return $chunks;
    }

    private function getLogExcerpt(string $logText): string
    {
        if (empty($logText)) {
            return '';
        }

        if (mb_strlen($logText) <= self::LOG_EXCERPT_LENGTH) {
            return $logText;
        }

        return mb_substr($logText, -self::LOG_EXCERPT_LENGTH);
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $url = self::API_URL . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Notion-Version: ' . self::API_VERSION,
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
            throw new \RuntimeException('Notion API request failed: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Notion API returned HTTP %d: %s',
                $httpCode,
                $response
            ));
        }

        return json_decode($response, true) ?? [];
    }
}
