<?php
namespace F122apg\YoutubeLiveChecker\GCP;

/**
 * メタデータサーバーからアクセストークンを取得する。
 *
 * 1リクエストで Batch / Cloud Logging / GCS を叩くため取得結果を使い回すが、
 * Cloud Run のインスタンスはリクエストをまたいで再利用されるので有効期限を見て取り直す。
 */
class MetadataToken
{
    private const METADATA_URL =
        'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';
    private const TIMEOUT = 10;

    /** 期限ぎりぎりのトークンで長い処理に入らないための余裕 */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private static ?string $token = null;
    private static int $expiresAt = 0;

    public static function get(): string
    {
        if (self::$token !== null && time() < self::$expiresAt) {
            return self::$token;
        }

        $ch = curl_init(self::METADATA_URL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException(
                'Failed to obtain an access token from the metadata server: ' . ($error ?: 'HTTP ' . $httpCode)
            );
        }

        $data = json_decode((string)$response, true);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Metadata server returned no access_token');
        }

        $expiresIn = (int)($data['expires_in'] ?? 0);
        self::$token = $token;
        self::$expiresAt = time() + max(0, $expiresIn - self::EXPIRY_MARGIN_SECONDS);

        return $token;
    }
}
