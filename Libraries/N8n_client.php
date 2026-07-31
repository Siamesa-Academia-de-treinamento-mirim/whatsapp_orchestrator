<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Services\Payload_sanitizer;
use InvalidArgumentException;
use RuntimeException;

class N8n_client
{
    private Chat_settings_model $settings;
    private Payload_sanitizer $sanitizer;

    /** @var callable|null */
    private $transport;

    public function __construct(?Chat_settings_model $settings = null, ?callable $transport = null)
    {
        $this->settings = $settings ?? new Chat_settings_model();
        $this->transport = $transport;
        $this->sanitizer = new Payload_sanitizer();
    }

    public function configured(): bool
    {
        return trim((string) $this->settings->get_value('n8n_base_url', '')) !== '';
    }

    public function health(): array
    {
        if (!$this->configured()) {
            return ['connected' => false, 'latency_ms' => 0, 'version' => null, 'message' => 'Configure a URL base do n8n.'];
        }
        $started = microtime(true);
        $response = $this->request('GET', (string) $this->settings->get_value('n8n_health_path', '/healthz'), null, ['idempotent' => true]);
        $data = is_array($response['data']) ? $response['data'] : [];
        return [
            'connected' => $response['success'],
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'version' => isset($data['version']) && is_scalar($data['version']) ? (string) $data['version'] : null,
            'message' => $response['success'] ? 'Conexao confirmada.' : (string) $response['error'],
        ];
    }

    /** @return array{success:bool,status_code:int,data:mixed,error:?string,correlation_id:string} */
    public function request(string $method, string $path, ?array $payload = null, array $options = []): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException('Metodo n8n nao suportado.');
        }
        $base = rtrim(trim((string) $this->settings->get_value('n8n_base_url', '')), '/');
        $pinnedIp = $this->assertSafeBaseUrl($base);
        if (!str_starts_with($path, '/') || str_contains($path, '://') || preg_match('/[\r\n#]/', $path)) {
            throw new InvalidArgumentException('Caminho n8n invalido.');
        }
        $correlationId = trim((string) ($options['correlation_id'] ?? '')) ?: $this->uuid();
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Correlation-Id: ' . $correlationId];
        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $options['idempotency_key']);
        }
        $token = trim((string) $this->settings->get_value('n8n_token', ''));
        $mode = strtolower(trim((string) $this->settings->get_value('n8n_auth_mode', 'bearer')));
        if ($token !== '') {
            if ($mode === 'header') {
                $name = trim((string) $this->settings->get_value('n8n_header_name', 'X-API-Key'));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $name)) {
                    throw new RuntimeException('Nome do header n8n invalido.');
                }
                $headers[] = $name . ': ' . $token;
            } elseif ($mode === 'hmac') {
                $headers[] = 'X-Impulso-Signature: sha256=' . hash_hmac('sha256', $body, $token);
            } else {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }

        $maxAttempts = !empty($options['idempotent']) || $method === 'GET' ? 3 : 1;
        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $last = $this->execute($method, $base . $path, $headers, $body, $pinnedIp);
            $status = (int) ($last['status_code'] ?? 0);
            if (($status >= 200 && $status < 300) || !in_array($status, [0, 408, 425, 429, 500, 502, 503, 504], true)) {
                break;
            }
            if ($attempt < $maxAttempts) {
                usleep(100000 * $attempt);
            }
        }
        $status = (int) ($last['status_code'] ?? 0);
        $decoded = $last['body'] ?? null;
        if (is_string($decoded) && trim($decoded) !== '') {
            $json = json_decode($decoded, true);
            $decoded = json_last_error() === JSON_ERROR_NONE ? $json : ['message' => mb_substr(trim($decoded), 0, 1000)];
        }
        $success = $status >= 200 && $status < 300 && empty($last['error']);
        $error = null;
        if (!$success) {
            $candidate = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? null) : null;
            $error = is_scalar($candidate) && trim((string) $candidate) !== '' ? mb_substr(trim((string) $candidate), 0, 500) : 'Falha ao comunicar com o n8n.';
        }
        log_message($success ? 'info' : 'error', 'Chatwoot_plugin n8n request completed.', [
            'method' => $method,
            'endpoint' => parse_url($path, PHP_URL_PATH),
            'status_code' => $status,
            'correlation_id' => $correlationId,
        ]);
        // Callers may need full campaign audiences or workflow responses. Keep
        // the usable shape here and apply bounded sanitization only to logs.
        return ['success' => $success, 'status_code' => $status, 'data' => $this->sanitizer->redact($decoded, [$token]), 'error' => $error, 'correlation_id' => $correlationId];
    }

    private function execute(string $method, string $url, array $headers, string $body, ?string $pinnedIp): array
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $method, $url, $headers, $body, ['timeout' => $this->timeout()]);
            return is_array($result) ? $result : ['status_code' => 0, 'body' => null, 'error' => true];
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($method !== 'GET' && $body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        if ($pinnedIp !== null) {
            $parts = parse_url($url);
            $host = (string) ($parts['host'] ?? '');
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
                curl_setopt($curl, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $pinnedIp]);
            }
        }
        $response = curl_exec($curl);
        $error = curl_errno($curl) !== 0;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status_code' => $status, 'body' => $response === false ? null : $response, 'error' => $error];
    }

    private function assertSafeBaseUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Configure uma URL base valida para o n8n.', 503);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('A URL base do n8n nao e segura.', 503);
        }
        $allowPrivate = (string) $this->settings->get_value('n8n_allow_private_networks', '0') === '1';
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (!$allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            throw new RuntimeException('A URL do n8n aponta para uma rede privada nao autorizada.', 503);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new RuntimeException('Nao foi possivel resolver o host configurado para o n8n.', 503);
        }
        foreach ($ips as $ip) {
            if (!$allowPrivate && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('A URL do n8n aponta para uma rede privada nao autorizada.', 503);
            }
        }
        // Pin the exact address checked above so a second DNS lookup cannot
        // redirect cURL to loopback, metadata or another private network.
        return (string) $ips[0];
    }

    private function timeout(): int
    {
        return min(120, max(3, (int) $this->settings->get_value('n8n_timeout_seconds', 30)));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
