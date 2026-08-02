<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

use F122apg\YoutubeLiveChecker\Log;

/**
 * 外部（OpenAI / Notion）へ出す全テキストを通す秘匿化処理。
 *
 * 設計方針:
 *   - fail-closed。正規表現処理に失敗した行は落とし、既知の資格情報が
 *     処理後も残っていた場合は例外を投げて送信自体を中止する。
 *   - IPは削除ではなく決定論的な仮名化を行う。
 *     「同じサーバーに何度も接続している」という分析上の信号を保つため。
 *   - 署名付きメディアURLはクエリだけでなくURL全体を置換する。
 *     パスにも識別子が含まれ、どのパラメータが能力を持つか列挙しきれないため。
 *
 * 仮名化の対応表はインスタンス内にのみ持ち、永続化しない。
 */
class Sanitizer
{
    /** 置換後もこれらが残っていたらサニタイザの不具合とみなし送信を中止する */
    private const RESIDUAL_SECRET_PATTERNS = [
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'OpenAI key' => '/\bsk-[A-Za-z0-9_\-]{20,}/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    /** @var array<string, string> 実IP → 仮名 */
    private array $ipAliases = [];

    private int $droppedLines = 0;

    /**
     * ログ行の配列を1行ずつ処理する。
     * 処理に失敗した行はマーカーへ差し替え、内容を外へ出さない。
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public function sanitizeLines(array $lines): array
    {
        $result = [];

        foreach ($lines as $line) {
            try {
                $result[] = $this->sanitize($line);
            } catch (\Throwable $e) {
                $this->droppedLines++;
                $result[] = '[SANITIZER_DROPPED_LINE]';
            }
        }

        if ($this->droppedLines > 0) {
            Log::warning(sprintf('Sanitizer dropped %d line(s) it could not process.', $this->droppedLines));
        }

        // 大量に落ちる場合はパターン側の不具合を疑うべきで、
        // 虫食いだらけのログで分析を続けても意味がない
        $total = count($lines);
        if ($total > 0 && $this->droppedLines / $total > 0.05) {
            throw new \RuntimeException(sprintf(
                'Sanitizer failed on %d of %d lines (>5%%). Aborting to avoid an unreliable analysis.',
                $this->droppedLines,
                $total
            ));
        }

        return $result;
    }

    /**
     * @throws \RuntimeException 置換に失敗した場合、または既知の資格情報が残った場合
     */
    public function sanitize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $result = $text;

        // 1. 鍵ブロックは最初に潰す。中身に他のパターンが誤マッチするのを避けるため
        $result = $this->replace('/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s', '[REDACTED_KEY_BLOCK]', $result);

        // 2. URL: 署名付きメディアURLはURLごと置換
        $result = $this->replace(
            '#https?://[^\s\'"]*\.googlevideo\.com/[^\s\'"]*#i',
            '[SIGNED_MEDIA_URL]',
            $result
        );
        // 能力を持つクエリを含むURLも同様に全体を置換
        $result = $this->replace(
            '#https?://[^\s\'"]*[?&](sig|signature|token|key|expire|pot|X-Amz-Signature|X-Goog-Signature)=[^\s\'"]*#i',
            '[SIGNED_URL]',
            $result
        );
        // URL埋め込みのBasic認証
        $result = $this->replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[REDACTED_CREDENTIALS]@', $result);

        // 3. 既知形式のトークン類
        $result = $this->replace('/\bAKIA[0-9A-Z]{16}\b/', '[AWS_ACCESS_KEY_ID]', $result);
        $result = $this->replace('/\bASIA[0-9A-Z]{16}\b/', '[AWS_TEMP_ACCESS_KEY_ID]', $result);
        $result = $this->replace('/\bAIza[0-9A-Za-z_\-]{35}\b/', '[GOOGLE_API_KEY]', $result);
        $result = $this->replace('/\bsk-[A-Za-z0-9_\-]{20,}/', '[OPENAI_API_KEY]', $result);
        $result = $this->replace('/\bgh[pousr]_[A-Za-z0-9]{36,}\b/', '[GITHUB_TOKEN]', $result);
        $result = $this->replace('/\bya29\.[A-Za-z0-9_\-]{20,}/', '[GOOGLE_OAUTH_TOKEN]', $result);
        // JWT
        $result = $this->replace('/\bey[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/', '[JWT]', $result);

        // 4. ヘッダ形式
        $result = $this->replace('/(authorization\s*:\s*)\S+.*/i', '$1[REDACTED]', $result);
        $result = $this->replace('/((?:set-)?cookie\s*:\s*).*/i', '$1[REDACTED]', $result);
        $result = $this->replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._\-=\/+]{16,}/i', '$1 [REDACTED]', $result);

        // 5. オブジェクトストレージのURI。バケット名だけ伏せ、パスは診断のため残す
        $result = $this->replace('#\b(s3|gs)://[^/\s\'"]+#i', '$1://[BUCKET]', $result);

        // 6. 秘密を示す語に続く値を伏せる。
        //    識別子形式 (API_KEY=x) だけでなく、人間向けの表記 (AWS Secret Access Key: x) や
        //    CLI引数 (--password x) も対象にする。語の区切りは空白・アンダースコア・ハイフンを許す。
        //    値は保守的に行末まで落とす。空白を含む値の後半が残るのを防ぐため。
        $sep = '[\s_\-]*';
        $secretPhrase = '(?:'
            . 'secret' . $sep . 'access' . $sep . 'key'
            . '|access' . $sep . 'token'
            . '|refresh' . $sep . 'token'
            . '|session' . $sep . 'token'
            . '|bearer' . $sep . 'token'
            . '|auth(?:orization)?' . $sep . 'token'
            . '|client' . $sep . 'secret'
            . '|private' . $sep . 'key'
            . '|api' . $sep . 'key'
            . '|access' . $sep . 'key'
            . '|passwo?r?d'
            . '|passphrase'
            . '|credential'
            . '|secret'
            . '|token'
            . ')';
        // 直前の修飾語も名前の一部として拾う (例: "AWS Secret Access Key")
        $namePattern = '((?:[A-Za-z0-9_\-]+[\s_\-]+)?' . $secretPhrase . ')';

        // (a) "..." / '...' で囲まれた値
        $result = $this->replace(
            '/' . $namePattern . '(\s*[:=]\s*)(\\\\?["\'])(?:(?!\3).)*\3/i',
            '$1$2$3[REDACTED]$3',
            $result
        );
        // (b) : または = に続く引用符なしの値
        $result = $this->replace(
            '/' . $namePattern . '(\s*[:=]\s*)[^\r\n]+/i',
            '$1$2[REDACTED]',
            $result
        );
        // (c) CLI引数形式。空白区切りのため (b) では拾えない。
        //     --db-password / --aws-secret-access-key のように修飾語が前置される形も対象にする
        $result = $this->replace(
            '/(--(?:[A-Za-z0-9]+[-_])*' . $secretPhrase . ')(\s+)[^\r\n]+/i',
            '$1$2[REDACTED]',
            $result
        );

        // 7. メールアドレス
        $result = $this->replace(
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            '[EMAIL]',
            $result
        );

        // 8. IPは仮名化（プライベート/リンクローカルは分析上有用かつ機微でないので残す）
        $result = $this->pseudonymizeIpv4($result);
        $result = $this->pseudonymizeIpv6($result);

        $this->assertNoResidualSecrets($result);

        return $result;
    }

    /**
     * VPNサーバーやVPN出口のIPは第三者の情報なので外部へ出さない。
     * ただし同一性は保ちたいので連番の仮名へ置き換える。
     */
    private function pseudonymizeIpv4(string $text): string
    {
        $callback = function (array $matches): string {
            $ip = $matches[0];

            if ($this->isNonSensitiveIp($ip)) {
                return $ip;
            }

            if (!isset($this->ipAliases[$ip])) {
                $this->ipAliases[$ip] = sprintf('[IP_%d]', count($this->ipAliases) + 1);
            }

            return $this->ipAliases[$ip];
        };

        $result = preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $callback, $text);

        if ($result === null) {
            throw new \RuntimeException('IP pseudonymization failed: ' . preg_last_error_msg());
        }

        return $result;
    }

    /**
     * IPv6も同様に仮名化する。候補を広めに拾ってから妥当性を検証し、
     * ログ中のタイムスタンプ (10:19:22) などを誤って置換しないようにする。
     */
    private function pseudonymizeIpv6(string $text): string
    {
        $callback = function (array $matches): string {
            $candidate = $matches[0];

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return $candidate;
            }

            // ループバック・リンクローカル・ユニークローカルは残す
            if (filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return $candidate;
            }

            if (!isset($this->ipAliases[$candidate])) {
                $this->ipAliases[$candidate] = sprintf('[IP_%d]', count($this->ipAliases) + 1);
            }

            return $this->ipAliases[$candidate];
        };

        // 末尾が "::" で終わる短縮表記 (2600:2d00:2:1000::) も拾えるよう末尾のコロンを許容する
        $result = preg_replace_callback(
            '/[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}:?/',
            $callback,
            $text
        );

        if ($result === null) {
            throw new \RuntimeException('IPv6 pseudonymization failed: ' . preg_last_error_msg());
        }

        return $result;
    }

    /**
     * データ部としてプロンプトへ埋め込む前に、境界タグを閉じられないようにする。
     * ログ本文に "</logs>" が含まれると区切りが壊れるため。
     */
    public function neutralizeTagBoundaries(string $text): string
    {
        return str_replace('</', '<\\/', $text);
    }

    /**
     * 配列の全文字列値を再帰的に処理する。
     * インシデント情報・Batchの実行時情報・モデル出力に共通で使う。
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function sanitizeArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->sanitize($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * プライベート・ループバック・リンクローカル・GCPメタデータは伏せない。
     * 第三者を特定しないうえ、ネットワーク構成の把握に必要なため。
     */
    private function isNonSensitiveIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            // IPらしき文字列だが妥当なIPではない（バージョン番号など）。触らない
            return true;
        }

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['0.0.0.0', '0.255.255.255'],
        ];

        foreach ($ranges as [$from, $to]) {
            if ($long >= ip2long($from) && $long <= ip2long($to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \RuntimeException
     */
    private function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new \RuntimeException(sprintf(
                'Sanitizer pattern failed (%s): %s',
                $pattern,
                preg_last_error_msg()
            ));
        }

        return $result;
    }

    /**
     * 置換漏れの最終確認。ここで引っかかるのはパターンの不備なので、
     * 黙って送らずに落とす。
     *
     * @throws \RuntimeException
     */
    private function assertNoResidualSecrets(string $text): void
    {
        foreach (self::RESIDUAL_SECRET_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new \RuntimeException(
                    'Sanitizer left a known credential in the output: ' . $label
                );
            }
        }
    }

    public function droppedLineCount(): int
    {
        return $this->droppedLines;
    }

    /**
     * 仮名化したIPの数。分析結果の読み手向けの補足情報。
     */
    public function pseudonymizedIpCount(): int
    {
        return count($this->ipAliases);
    }
}
