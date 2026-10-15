<?php

/** Minimaler BrickLink-API-Client (OAuth 1.0, HMAC-SHA1). */
final class BrickLinkClient
{
    private const BASE = 'https://api.bricklink.com/api/store/v1';

    public function __construct(private array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        foreach (['consumer_key', 'consumer_secret', 'token', 'token_secret'] as $k) {
            if (empty($this->cfg[$k])) {
                return false;
            }
        }
        return true;
    }

    public function get(string $path, array $query = []): mixed
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('BrickLink-Zugangsdaten fehlen in api/config.php');
        }
        $url = self::BASE . $path;
        $oauth = [
            'oauth_consumer_key'     => $this->cfg['consumer_key'],
            'oauth_token'            => $this->cfg['token'],
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_version'          => '1.0',
        ];
        $oauth['oauth_signature'] = $this->sign('GET', $url, array_merge($query, $oauth));

        $header = 'OAuth ' . implode(', ', array_map(
            fn ($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
            array_keys($oauth),
            $oauth
        ));

        $ch = curl_init($url . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $header, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('BrickLink nicht erreichbar: ' . $err);
        }
        curl_close($ch);

        $json = json_decode($body, true);
        $code = $json['meta']['code'] ?? 0;
        if ($code !== 200) {
            throw new RuntimeException('BrickLink-Fehler: ' . ($json['meta']['description'] ?? 'unbekannt'));
        }
        return $json['data'];
    }

    private function sign(string $method, string $url, array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $pairs));
        $key = rawurlencode($this->cfg['consumer_secret']) . '&' . rawurlencode($this->cfg['token_secret']);
        return base64_encode(hash_hmac('sha1', $base, $key, true));
    }
}
