<?php

/** Fehler einer Plattform-API mit HTTP- bzw. BrickLink-Statuscode (für Wiederholungsregeln). */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

/**
 * Protokoll aller Abrufe bei BrickLink und BrickOwl (Tabelle api_calls).
 * So bleibt das Tageslimit sichtbar (Dev-Bereich, Minifig-Finder).
 */
final class ApiLog
{
    public static function log(string $api, string $type, string $endpoint, int $status): void
    {
        try {
            q('INSERT INTO {api_calls} (api, call_type, endpoint, status, created_at) VALUES (?, ?, ?, ?, ?)',
                [$api, $type, substr($endpoint, 0, 255), $status, now()]);
        } catch (Throwable $e) { /* Protokoll darf nie den Abruf verhindern */ }
    }

    /** Abrufe der letzten 24 Stunden, gesamt und je Art. */
    public static function usage24h(?string $api = null): array
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $where = 'created_at >= ?' . ($api ? ' AND api = ?' : '');
        $p = $api ? [$since, $api] : [$since];
        $byType = [];
        foreach (q("SELECT call_type, COUNT(*) AS n FROM {api_calls} WHERE $where GROUP BY call_type ORDER BY COUNT(*) DESC", $p)->fetchAll() as $r) {
            $byType[$r['call_type']] = (int) $r['n'];
        }
        return ['total' => array_sum($byType), 'by_type' => $byType];
    }

    public static function countSince(string $type, string $since): int
    {
        return (int) q('SELECT COUNT(*) FROM {api_calls} WHERE call_type = ? AND created_at >= ?', [$type, $since])->fetchColumn();
    }
}

/**
 * BrickLink-API-Client (OAuth 1.0, HMAC-SHA1).
 * Ohne Internet testbar: Ist bricklink.fixture_dir gesetzt, kommen die Antworten aus JSON-Dateien
 * (Pfad /orders/123/items → orders_123_items.json, Inhalt = das „data“-Feld der API-Antwort).
 */
final class BrickLinkClient
{
    private const BASE = 'https://api.bricklink.com/api/store/v1';

    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config()['bricklink'] ?? []);
    }

    public function isConfigured(): bool
    {
        if (!empty($this->cfg['fixture_dir'])) return true;
        foreach (['consumer_key', 'consumer_secret', 'token', 'token_secret'] as $k) {
            if (empty($this->cfg[$k])) {
                return false;
            }
        }
        return true;
    }

    /** GET-Abruf; $type benennt den Abruf im Protokoll (z. B. 'orders', 'catalog_subsets'). */
    public function get(string $path, array $query = [], string $type = 'other'): mixed
    {
        if (!$this->isConfigured()) {
            throw new ApiException('BrickLink-Zugangsdaten fehlen (.env oder api/config.php)', 401);
        }
        $endpoint = $path . ($query ? '?' . http_build_query($query) : '');
        if (!empty($this->cfg['fixture_dir'])) {
            return self::fixture($this->cfg['fixture_dir'], $path, 'bricklink', $type, $endpoint);
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
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            ApiLog::log('bricklink', $type, $endpoint, 0);
            throw new ApiException('BrickLink nicht erreichbar: ' . $err, 0);
        }
        curl_close($ch);

        $json = json_decode($body, true);
        // BrickLink meldet Fehler oft mit HTTP 200 und meta.code
        $code = (int) ($json['meta']['code'] ?? $http);
        ApiLog::log('bricklink', $type, $endpoint, $code);
        if ($code !== 200) {
            throw new ApiException('BrickLink-Fehler: ' . ($json['meta']['description'] ?? $json['meta']['message'] ?? "HTTP $http"), $code);
        }
        return $json['data'];
    }

    /** Antwort aus einer Testdatei (ohne Internet). Fehlt die Datei: 404 wie bei BrickLink. */
    public static function fixture(string $dir, string $path, string $api, string $type, string $endpoint): mixed
    {
        $file = rtrim($dir, '/') . '/' . trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $path), '_') . '.json';
        if (!is_file($file)) {
            ApiLog::log($api, $type, $endpoint, 404);
            throw new ApiException("Testdatei fehlt: " . basename($file), 404);
        }
        ApiLog::log($api, $type, $endpoint, 200);
        return json_decode(file_get_contents($file), true);
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

/** BrickOwl-API (Schlüssel als Query-Parameter), nur lesend für Bestellungen. */
final class BrickOwlClient
{
    private const BASE = 'https://api.brickowl.com/v1';

    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config()['brickowl'] ?? []);
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['api_key']) || !empty($this->cfg['fixture_dir']);
    }

    public function get(string $path, array $query = [], string $type = 'other'): mixed
    {
        if (!$this->isConfigured()) {
            throw new ApiException('BrickOwl-Schlüssel fehlt (.env oder api/config.php)', 401);
        }
        $endpoint = $path . ($query ? '?' . http_build_query($query) : '');
        if (!empty($this->cfg['fixture_dir'])) {
            return BrickLinkClient::fixture($this->cfg['fixture_dir'], 'bo' . $path . (isset($query['order_id']) ? '/' . $query['order_id'] : ''), 'brickowl', $type, $endpoint);
        }
        $ch = curl_init(self::BASE . $path . '?' . http_build_query(['key' => $this->cfg['api_key']] + $query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        ApiLog::log('brickowl', $type, $endpoint, $body === false ? 0 : $http);
        if ($body === false) throw new ApiException('BrickOwl nicht erreichbar: ' . $err, 0);
        if ($http < 200 || $http >= 300) throw new ApiException("BrickOwl-Fehler (HTTP $http)", $http);
        return json_decode($body, true);
    }
}
