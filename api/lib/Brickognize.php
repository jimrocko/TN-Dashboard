<?php

/**
 * Teileerkennung per Foto über Brickognize (kostenlos, https://brickognize.com).
 * Das Bild wird unverändert weitergereicht; zurück kommen die wahrscheinlichsten Teile, Minifigs oder Sets.
 */
final class Brickognize
{
    public const DEFAULT_URL = 'https://api.brickognize.com/predict/';

    /** @return list<array{id:string,name:string,type:string,category:string,score:float,img_url:?string,bricklink_url:?string}> */
    public static function predict(string $path, string $mime, array $cfg = []): array
    {
        $url = array_key_exists('url', $cfg) ? $cfg['url'] : self::DEFAULT_URL;
        if (!$url) {
            throw new RuntimeException('Teileerkennung ist abgeschaltet (api/config.php, brickognize.url)');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['query_image' => new CURLFile($path, $mime, 'foto')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?? 30),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Erkennungsdienst nicht erreichbar: ' . $err);
        }
        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data)) {
            throw new RuntimeException("Erkennungsdienst antwortet mit Fehler (HTTP $status)");
        }
        return self::normalize($data);
    }

    /** Antwort auf die Felder reduzieren, die die App braucht. */
    public static function normalize(array $data): array
    {
        $out = [];
        foreach (array_slice($data['items'] ?? [], 0, 6) as $it) {
            if (!isset($it['id'])) continue;
            $bl = null;
            foreach ($it['external_sites'] ?? [] as $site) {
                if (stripos((string) ($site['name'] ?? ''), 'bricklink') !== false) $bl = $site['url'] ?? null;
            }
            $out[] = [
                'id'            => (string) $it['id'],
                'name'          => (string) ($it['name'] ?? ''),
                'type'          => (string) ($it['type'] ?? 'part'),
                'category'      => (string) ($it['category'] ?? ''),
                'score'         => round((float) ($it['score'] ?? 0), 3),
                'img_url'       => $it['img_url'] ?? null,
                'bricklink_url' => $bl,
            ];
        }
        return $out;
    }
}
