<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use Chatwoot_plugin\Services\Payload_sanitizer;

require_once dirname(__DIR__) . '/Services/Payload_sanitizer.php';

/**
 * Small Evolution API v2 client isolated from controllers and views.
 *
 * An injected transport receives:
 *   ($method, $url, $headers, $payload, $options)
 * and returns an array containing status_code/status, body/data and error.
 */
class Evolution_client
{
    private const DEFAULT_ENDPOINTS = [
        'status' => '/instance/connectionState/{instance}',
        'chats' => '/chat/findChats/{instance}',
        'messages' => '/chat/findMessages/{instance}',
        'send_text' => '/message/sendText/{instance}',
        'send_media' => '/message/sendMedia/{instance}',
        'send_reaction' => '/message/sendReaction/{instance}',
        'send_audio' => '/message/sendWhatsAppAudio/{instance}',
        'media_base64' => '/chat/getBase64FromMediaMessage/{instance}',
    ];

    private const SETTING_ENDPOINTS = [
        'status' => 'evolution_endpoint_connection_state',
        'chats' => 'evolution_endpoint_find_chats',
        'messages' => 'evolution_endpoint_find_messages',
        'send_text' => 'evolution_endpoint_send_text',
        'send_media' => 'evolution_endpoint_send_media',
        'send_reaction' => 'evolution_endpoint_send_reaction',
        'send_audio' => 'evolution_endpoint_send_audio',
        'media_base64' => 'evolution_endpoint_media_base64',
    ];

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $instance;

    /** @var array<string, string> */
    private array $endpoints;

    /** @var callable|object|null */
    private $transport;

    /** @var object|null */
    private $settingsModel;

    /** @var object|null */
    private $credentialCipher;

    /** @var callable|null */
    private $logger;

    private Payload_sanitizer $sanitizer;
    private int $connectTimeout;
    private int $timeout;
    private bool $requireApiKey;

    /** @var array<string, mixed>|null */
    private ?array $settingsCache = null;

    /**
     * @param array<string, mixed> $config Instance fields may be passed directly or under "instance".
     * @param callable|object|null $transport
     * @param object|null $settingsModel
     * @param object|null $credentialCipher
     * @param callable|null $logger
     */
    public function __construct(
        array $config = [],
        $transport = null,
        $settingsModel = null,
        $credentialCipher = null,
        $logger = null,
        ?Payload_sanitizer $sanitizer = null
    ) {
        $this->config = $config;
        $this->instance = $this->toArray($config['instance'] ?? $config);
        $this->transport = $transport;
        $this->settingsModel = $settingsModel;
        $this->credentialCipher = $credentialCipher;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->sanitizer = $sanitizer ?? new Payload_sanitizer();
        $this->requireApiKey = !array_key_exists('require_api_key', $config) || (bool) $config['require_api_key'];
        $configuredEndpoints = is_array($config['endpoints'] ?? null) ? $config['endpoints'] : [];
        $settings = $this->loadSettings();

        $this->endpoints = self::DEFAULT_ENDPOINTS;
        foreach (self::SETTING_ENDPOINTS as $endpoint => $settingKey) {
            if (isset($settings[$settingKey]) && is_scalar($settings[$settingKey])) {
                $value = trim((string) $settings[$settingKey]);
                if ($value !== '') {
                    $this->endpoints[$endpoint] = $value;
                }
            }
        }
        $this->endpoints = array_merge($this->endpoints, $configuredEndpoints);

        $this->connectTimeout = max(1, (int) ($config['connect_timeout'] ?? 5));
        $timeout = $config['timeout'] ?? $settings['evolution_timeout_seconds'] ?? 20;
        $this->timeout = max($this->connectTimeout, (int) $timeout);
    }

    /** @param array<string, mixed>|object $instance */
    public function with_instance($instance): self
    {
        $clone = clone $this;
        $clone->instance = $this->toArray($instance);

        return $clone;
    }

    /** @see with_instance() */
    public function withInstance($instance): self
    {
        return $this->with_instance($instance);
    }

    /**
     * @param array<string, mixed>|object|string|null $instance
     * @return array<string, mixed>
     */
    public function status($instance = null): array
    {
        $response = $this->requestEndpoint('GET', 'status', null, $instance);
        $state = $response['success'] ? $this->extractConnectionState($response['data']) : null;
        $response['state'] = $state;
        $response['connection_status'] = $response['success']
            ? $this->map_connection_state($state)
            : 'error';

        return $response;
    }

    /** @return array<string, mixed> */
    public function connection_status($instance = null): array
    {
        return $this->status($instance);
    }

    /** @return array<string, mixed> */
    public function get_connection_status($instance = null): array
    {
        return $this->status($instance);
    }

    /** @return array<string, mixed> */
    public function getConnectionState($instance = null): array
    {
        return $this->status($instance);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function find_chats(array $filters = [], $instance = null): array
    {
        return $this->requestEndpoint('POST', 'chats', $filters, $instance);
    }

    /** @return array<string, mixed> */
    public function chats(array $filters = [], $instance = null): array
    {
        return $this->find_chats($filters, $instance);
    }

    /** @return array<string, mixed> */
    public function findChats(array $filters = [], $instance = null): array
    {
        return $this->find_chats($filters, $instance);
    }

    /**
     * @param array<string, mixed> $options Additional Evolution v2 filters/pagination.
     * @return array<string, mixed>
     */
    public function find_messages(string $remoteJid, array $options = [], $instance = null): array
    {
        if (trim($remoteJid) === '') {
            return $this->failure('validation_error', 'remoteJid e obrigatorio.');
        }

        $payload = $options;
        $where = is_array($payload['where'] ?? null) ? $payload['where'] : [];
        $key = is_array($where['key'] ?? null) ? $where['key'] : [];
        $key['remoteJid'] = trim($remoteJid);
        $where['key'] = $key;
        $payload['where'] = $where;

        return $this->requestEndpoint('POST', 'messages', $payload, $instance);
    }

    /** @return array<string, mixed> */
    public function messages(string $remoteJid, array $options = [], $instance = null): array
    {
        return $this->find_messages($remoteJid, $options, $instance);
    }

    /** @return array<string, mixed> */
    public function findMessages(string $remoteJid, array $options = [], $instance = null): array
    {
        return $this->find_messages($remoteJid, $options, $instance);
    }

    /**
     * @return array<string, mixed>
     */
    public function send_text(string $number, string $text, $instance = null): array
    {
        return $this->send_text_with_context($number, $text, $instance, []);
    }

    /** @return array<string, mixed> */
    public function send_text_with_context(string $number, string $text, $instance = null, array $options = []): array
    {
        $number = $this->normalizeNumber($number);
        if ($number === '') {
            return $this->failure('validation_error', 'Numero de destino invalido.');
        }

        if (trim($text) === '') {
            return $this->failure('validation_error', 'A mensagem nao pode estar vazia.');
        }

        $payload = ['number' => $number, 'text' => $text];
        $quoted = $this->quotedPayload($options);
        if ($quoted !== null) $payload['quoted'] = $quoted;
        $response = $this->requestEndpoint(
            'POST',
            'send_text',
            $payload,
            $instance
        );
        $response['message_id'] = $response['success']
            ? $this->extract_message_id($response['data'])
            : null;

        return $response;
    }

    /** @return array<string, mixed> */
    public function sendText(string $number, string $text, $instance = null): array
    {
        return $this->send_text($number, $text, $instance);
    }

    /** @return array<string,mixed> */
    public function send_media(string $number, string $media, string $mimeType, string $mediaType, string $fileName = '', string $caption = '', $instance = null, array $options = []): array
    {
        $number = $this->normalizeNumber($number);
        if ($number === '' || trim($media) === '') {
            return $this->failure('validation_error', 'Numero e midia sao obrigatorios.');
        }
        $payload = [
            'number' => $number,
            'mediatype' => $mediaType,
            'mimetype' => $mimeType,
            'media' => $media,
            'caption' => $caption,
        ];
        if ($fileName !== '') {
            $payload['fileName'] = $fileName;
        }
        $isVoiceNote = $mediaType === 'audio' && !empty($options['voice_note']);
        $quoted = $this->quotedPayload($options);
        if ($quoted !== null) {
            if ($isVoiceNote) $voicePayload = ['number' => $number, 'audio' => $media, 'encoding' => true, 'ptt' => true, 'quoted' => $quoted];
            else $payload['quoted'] = $quoted;
        }
        $response = $this->requestEndpoint('POST', $isVoiceNote ? 'send_audio' : 'send_media', $isVoiceNote ? ($voicePayload ?? ['number' => $number, 'audio' => $media, 'encoding' => true, 'ptt' => true]) : $payload, $instance);
        $response['message_id'] = $response['success'] ? $this->extract_message_id($response['data']) : null;
        return $response;
    }

    /** @return array<string,mixed> */
    public function sendMedia(string $number, string $media, string $mimeType, string $mediaType, string $fileName = '', string $caption = '', $instance = null, array $options = []): array
    {
        return $this->send_media($number, $media, $mimeType, $mediaType, $fileName, $caption, $instance, $options);
    }

    /** @return array<string,mixed> */
    public function send_reaction(string $number, string $messageId, string $emoji, $instance = null, array $options = []): array
    {
        $number = $this->normalizeNumber($number);
        $messageId = trim($messageId);
        if ($number === '' || $messageId === '') return $this->failure('validation_error', 'Numero e mensagem alvo sao obrigatorios.');
        $remoteJid = trim((string) ($options['remote_jid'] ?? '')) ?: $number . '@s.whatsapp.net';
        $payload = [
            'reactionKey' => [
                'remoteJid' => $remoteJid,
                'fromMe' => !empty($options['from_me']),
                'id' => $messageId,
            ],
            'reactionMessage' => mb_substr($emoji, 0, 16),
        ];
        $participant = trim((string) ($options['participant'] ?? ''));
        if ($participant !== '' && str_ends_with(strtolower($remoteJid), '@g.us')) {
            $payload['reactionKey']['participant'] = $participant;
        }
        $response = $this->requestEndpoint('POST', 'send_reaction', $payload, $instance);
        $response['message_id'] = $response['success'] ? $this->extract_message_id($response['data']) : null;
        return $response;
    }

    /** @return array<string,mixed> */
    public function sendReaction(string $number, string $messageId, string $emoji, $instance = null, array $options = []): array
    {
        return $this->send_reaction($number, $messageId, $emoji, $instance, $options);
    }

    /** @param array<string,mixed> $options @return array<string,mixed>|null */
    private function quotedPayload(array $options): ?array
    {
        $id = trim((string) ($options['reply_to_external_message_id'] ?? ''));
        if ($id === '') return null;
        return [
            'key' => [
                'remoteJid' => trim((string) ($options['reply_to_remote_jid'] ?? '')),
                'fromMe' => !empty($options['reply_to_from_me']),
                'id' => $id,
            ],
        ];
    }

    /**
     * Resolves encrypted/directPath media through Evolution without exposing
     * the provider API key to the browser.
     *
     * @return array<string,mixed>
     */
    public function get_media_base64(array $message, $instance = null): array
    {
        if ($message === []) {
            return $this->failure('invalid_payload', 'Payload da mensagem de midia ausente.');
        }
        $response = $this->requestEndpoint('POST', 'media_base64', [
            'message' => $message,
            'convertToMp4' => false,
        ], $instance);
        if (!$response['success']) {
            return $response;
        }
        $found = $this->extractBase64Payload($response['data']);
        if ($found === null) {
            return $this->failure('invalid_response', 'A Evolution nao retornou o conteudo da midia.');
        }
        $response['base64'] = $found['base64'];
        $response['mime_type'] = $found['mime_type'];

        return $response;
    }

    /** @param mixed $value @return array{base64:string,mime_type:?string}|null */
    private function extractBase64Payload($value): ?array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_string($value)) {
            $candidate = trim($value);
            $mime = null;
            if (preg_match('#^data:([^;,]+);base64,(.+)$#s', $candidate, $matches)) {
                $mime = trim((string) $matches[1]);
                $candidate = trim((string) $matches[2]);
            }
            if (strlen($candidate) >= 16 && preg_match('/^[A-Za-z0-9+\/_=-]+$/', $candidate)) {
                return ['base64' => $candidate, 'mime_type' => $mime];
            }
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach (['base64', 'mediaBase64', 'fileBase64'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $found = $this->extractBase64Payload($value[$key]);
                if ($found) {
                    $found['mime_type'] ??= isset($value['mimetype']) ? (string) $value['mimetype'] : (isset($value['mimeType']) ? (string) $value['mimeType'] : null);
                    return $found;
                }
            }
        }
        foreach ($value as $child) {
            if (is_array($child) || is_object($child)) {
                $found = $this->extractBase64Payload($child);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Low-level GET for minor Evolution version differences centralized by callers.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, $instance = null): array
    {
        return $this->request('GET', $path, null, $instance);
    }

    /**
     * Low-level POST for minor Evolution version differences centralized by callers.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], $instance = null): array
    {
        return $this->request('POST', $path, $payload, $instance);
    }

    /**
     * Low-level DELETE for Evolution instance lifecycle operations.
     *
     * @return array<string, mixed>
     */
    public function delete(string $path, $instance = null): array
    {
        return $this->request('DELETE', $path, null, $instance);
    }

    /** @return array<string, mixed> */
    public function fetch_instances(): array
    {
        return $this->request('GET', '/instance/fetchInstances');
    }

    /** @param array<string, mixed> $payload */
    public function create_instance(array $payload, $instance = null): array
    {
        return $this->request('POST', '/instance/create', $payload, $instance);
    }

    /** @return array<string, mixed> */
    public function connect_instance($instance = null, ?string $number = null): array
    {
        $resolvedInstance = $this->normalizeInstanceOverride($instance);
        $name = $this->instanceName($resolvedInstance);
        if ($name === '') {
            return $this->failure('configuration_error', 'Nome da instancia Evolution nao configurado.');
        }

        $query = [];
        if ($number !== null && trim($number) !== '') {
            $query['number'] = trim($number);
        }

        return $this->request(
            'GET',
            '/instance/connect/' . rawurlencode($name),
            null,
            $resolvedInstance,
            $query
        );
    }

    /** @return array<string, mixed> */
    public function restart_instance($instance = null): array
    {
        return $this->instanceLifecycleRequest('POST', '/instance/restart/', $instance);
    }

    /** @return array<string, mixed> */
    public function logout_instance($instance = null): array
    {
        return $this->instanceLifecycleRequest('DELETE', '/instance/logout/', $instance);
    }

    /** @return array<string, mixed> */
    public function delete_instance($instance = null): array
    {
        return $this->instanceLifecycleRequest('DELETE', '/instance/delete/', $instance);
    }

    /** @return array<string, mixed> */
    public function find_webhook($instance = null): array
    {
        return $this->instanceManagementRequest('GET', '/webhook/find/', $instance);
    }

    /** @param array<string, mixed> $payload */
    public function set_webhook($instance, array $payload): array
    {
        return $this->instanceManagementRequest('POST', '/webhook/set/', $instance, ['webhook' => $payload]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|object|string|null $instance
     * @param array<string, scalar|array<int, scalar>> $query
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $payload = null, $instance = null, array $query = []): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            return $this->failure('unsupported_method', 'Metodo HTTP nao suportado.');
        }

        $resolved = $this->resolveConfiguration($instance);
        if ($resolved['error'] !== null) {
            return $this->failure('configuration_error', $resolved['error']);
        }

        $url = $this->buildUrl($resolved['base_url'], $path);
        if ($url === null) {
            return $this->failure('configuration_error', 'URL da Evolution API invalida.');
        }
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($resolved['api_key'] !== '') {
            $headers['apikey'] = $resolved['api_key'];
        }

        $startedAt = microtime(true);
        try {
            $raw = $this->executeTransport($method, $url, $headers, $payload);
        } catch (\Throwable $exception) {
            $this->safeLog('error', 'Evolution request transport exception.', [
                'method' => $method,
                'endpoint' => $this->safeEndpoint($path),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return $this->failure('transport_error', 'Falha ao comunicar com a Evolution API.');
        }

        $response = $this->normalizeResponse($raw, $resolved['api_key']);
        $this->safeLog($response['success'] ? 'info' : 'error', 'Evolution request completed.', [
            'method' => $method,
            'endpoint' => $this->safeEndpoint($path),
            'status_code' => $response['status_code'],
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return $response;
    }

    public function map_connection_state(?string $state): string
    {
        $state = strtolower(trim((string) $state));

        if (in_array($state, ['open', 'connected', 'online'], true)) {
            return 'connected';
        }

        if ($state === 'connecting') {
            return 'attention';
        }

        return 'disconnected';
    }

    /** @see map_connection_state() */
    public function mapConnectionState(?string $state): string
    {
        return $this->map_connection_state($state);
    }

    /** @param mixed $data */
    public function extract_message_id($data): ?string
    {
        $array = $this->toArray($data);
        $keyId = $this->findNestedKeyId($array);
        if ($keyId !== null) {
            return $keyId;
        }

        $paths = [
            ['message_id'],
            ['messageId'],
            ['id'],
            ['message', 'id'],
            ['data', 'message_id'],
            ['data', 'messageId'],
            ['data', 'id'],
            ['response', 'key', 'id'],
        ];

        foreach ($paths as $path) {
            $value = $this->pathValue($array, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /** @see extract_message_id() */
    public function extractMessageId($data): ?string
    {
        return $this->extract_message_id($data);
    }

    /** @return array<string, mixed> */
    private function requestEndpoint(string $method, string $endpoint, ?array $payload, $instance): array
    {
        $resolvedInstance = $this->normalizeInstanceOverride($instance);
        $instanceName = $this->instanceName($resolvedInstance);
        if ($instanceName === '') {
            return $this->failure('configuration_error', 'Nome da instancia Evolution nao configurado.');
        }

        $template = $this->endpoints[$endpoint] ?? null;
        if (!is_string($template) || $template === '') {
            return $this->failure('configuration_error', 'Endpoint da Evolution API nao configurado.');
        }

        $path = str_replace('{instance}', rawurlencode($instanceName), $template);

        return $this->request($method, $path, $payload, $resolvedInstance);
    }

    /** @return array<string, mixed> */
    private function instanceLifecycleRequest(string $method, string $prefix, $instance): array
    {
        $resolvedInstance = $this->normalizeInstanceOverride($instance);
        $name = $this->instanceName($resolvedInstance);
        if ($name === '') {
            return $this->failure('configuration_error', 'Nome da instancia Evolution nao configurado.');
        }

        return $this->request($method, $prefix . rawurlencode($name), null, $resolvedInstance);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function instanceManagementRequest(string $method, string $prefix, $instance, ?array $payload = null): array
    {
        $resolvedInstance = $this->normalizeInstanceOverride($instance);
        $name = $this->instanceName($resolvedInstance);
        if ($name === '') {
            return $this->failure('configuration_error', 'Nome da instancia Evolution nao configurado.');
        }

        return $this->request($method, $prefix . rawurlencode($name), $payload, $resolvedInstance);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function executeTransport(string $method, string $url, array $headers, ?array $payload): array
    {
        $options = [
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
            'follow_redirects' => false,
            'verify_tls' => true,
        ];

        if (is_callable($this->transport)) {
            $response = call_user_func($this->transport, $method, $url, $headers, $payload, $options);

            return is_array($response) ? $response : ['status_code' => 0, 'body' => null, 'error' => true];
        }

        if (is_object($this->transport) && method_exists($this->transport, 'request')) {
            $response = $this->transport->request($method, $url, $headers, $payload, $options);

            return is_array($response) ? $response : ['status_code' => 0, 'body' => null, 'error' => true];
        }

        return $this->curlTransport($method, $url, $headers, $payload, $options);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function curlTransport(string $method, string $url, array $headers, ?array $payload, array $options): array
    {
        if (!function_exists('curl_init')) {
            return ['status_code' => 0, 'body' => null, 'error' => true, 'curl_errno' => -1];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return ['status_code' => 0, 'body' => null, 'error' => true, 'curl_errno' => -1];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => (int) $options['connect_timeout'],
            CURLOPT_TIMEOUT => (int) $options['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($method === 'POST') {
            $body = $payload === []
                ? '{}'
                : json_encode($payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($body)) {
                curl_close($curl);

                return ['status_code' => 0, 'body' => null, 'error' => true, 'curl_errno' => -2];
            }
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $curlOptions);
        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);

        return [
            'status_code' => $statusCode,
            'body' => is_string($body) ? $body : null,
            'error' => $curlError !== 0 || $body === false,
            'curl_errno' => $curlError,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $raw, string $apiKey): array
    {
        $statusCode = (int) ($raw['status_code'] ?? $raw['status'] ?? $raw['http_status'] ?? 0);
        $transportError = !empty($raw['error']) && !is_array($raw['error']);
        $body = $raw['body'] ?? $raw['data'] ?? null;

        if (is_string($body)) {
            $trimmed = trim($body);
            if ($trimmed === '') {
                $decoded = null;
            } else {
                $candidate = json_decode($trimmed, true);
                $decoded = json_last_error() === JSON_ERROR_NONE ? $candidate : $trimmed;
            }
        } elseif (is_object($body)) {
            $decoded = $this->toArray($body);
        } else {
            $decoded = $body;
        }

        $decoded = $this->sanitizer->redact($decoded, [$apiKey]);
        $httpSuccess = $statusCode >= 200 && $statusCode < 300;
        $applicationError = is_array($decoded)
            && array_key_exists('error', $decoded)
            && $decoded['error'] !== null
            && $decoded['error'] !== false
            && $decoded['error'] !== '';
        $success = !$transportError && $httpSuccess && !$applicationError;

        if ($success) {
            return $this->success($statusCode, $decoded);
        }

        $message = $transportError
            ? 'Falha ao comunicar com a Evolution API.'
            : $this->extractSafeError($decoded, $apiKey);

        return $this->failure('evolution_api_error', $message, $statusCode, $decoded);
    }

    /**
     * @param array<string, mixed>|object|string|null $instance
     * @return array{base_url:string,api_key:string,error:?string}
     */
    private function resolveConfiguration($instance): array
    {
        $instanceData = $this->normalizeInstanceOverride($instance);
        $settings = $this->loadSettings();

        $instanceBaseUrl = $this->firstString($instanceData, ['base_url', 'evolution_url', 'evolution_base_url']);
        $globalBaseUrl = $this->firstString($settings, ['base_url', 'evolution_url', 'evolution_base_url']);
        $baseUrl = $instanceBaseUrl !== '' ? $instanceBaseUrl : $globalBaseUrl;

        $apiKey = $this->firstString($instanceData, ['api_key']);
        if ($apiKey === '') {
            $apiKey = $this->decryptFirst($instanceData, ['api_key_encrypted']);
        }
        if ($apiKey === '' && $instanceBaseUrl !== '') {
            // A global secret is trusted only for the configured global origin.
            // Without this guard, an instance manager could point an override to
            // another host and make the server disclose the global key in apikey.
            if ($globalBaseUrl === '' || !$this->sameOrigin($instanceBaseUrl, $globalBaseUrl)) {
                return [
                    'base_url' => '',
                    'api_key' => '',
                    'error' => 'Uma URL especifica de outra origem exige uma API key propria da instancia.',
                ];
            }
        }
        if ($apiKey === '') {
            $apiKey = $this->firstString($settings, ['api_key', 'global_api_key', 'evolution_api_key']);
        }
        if ($apiKey === '') {
            $apiKey = $this->decryptFirst($settings, [
                'api_key_encrypted',
                'global_api_key_encrypted',
                'evolution_api_key_encrypted',
            ]);
        }

        if ($baseUrl === '') {
            return ['base_url' => '', 'api_key' => '', 'error' => 'URL base da Evolution API nao configurada.'];
        }

        if ($this->requireApiKey && $apiKey === '') {
            return ['base_url' => '', 'api_key' => '', 'error' => 'Credencial da Evolution API nao configurada.'];
        }

        return ['base_url' => rtrim($baseUrl, '/'), 'api_key' => $apiKey, 'error' => null];
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);
        if (!is_array($leftParts) || !is_array($rightParts)) {
            return false;
        }

        $leftScheme = strtolower((string) ($leftParts['scheme'] ?? ''));
        $rightScheme = strtolower((string) ($rightParts['scheme'] ?? ''));
        $leftHost = strtolower(rtrim((string) ($leftParts['host'] ?? ''), '.'));
        $rightHost = strtolower(rtrim((string) ($rightParts['host'] ?? ''), '.'));
        if ($leftScheme === '' || $rightScheme === '' || $leftHost === '' || $rightHost === '') {
            return false;
        }

        $leftPort = (int) ($leftParts['port'] ?? ($leftScheme === 'https' ? 443 : 80));
        $rightPort = (int) ($rightParts['port'] ?? ($rightScheme === 'https' ? 443 : 80));

        return $leftScheme === $rightScheme
            && $leftHost === $rightHost
            && $leftPort === $rightPort;
    }

    /** @return array<string, mixed> */
    private function loadSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $model = $this->resolveSettingsModel();
        if ($model === null) {
            return $this->settingsCache = [];
        }

        foreach (['get_settings', 'getSettings', 'get_all_settings', 'getAllSettings'] as $method) {
            if (!method_exists($model, $method)) {
                continue;
            }

            try {
                $result = $model->{$method}();
                $settings = $this->unwrapModelResult($result);
                if ($settings !== []) {
                    return $this->settingsCache = $settings;
                }
            } catch (\Throwable $exception) {
                return $this->settingsCache = [];
            }
        }

        $settings = [];
        $keys = [
            'base_url',
            'evolution_url',
            'evolution_base_url',
            'api_key',
            'global_api_key',
            'evolution_api_key',
            'api_key_encrypted',
            'global_api_key_encrypted',
            'evolution_api_key_encrypted',
            'evolution_timeout_seconds',
            'evolution_endpoint_connection_state',
            'evolution_endpoint_find_chats',
            'evolution_endpoint_find_messages',
            'evolution_endpoint_send_text',
            'evolution_endpoint_send_reaction',
        ];
        foreach ($keys as $key) {
            foreach (['get_value', 'getValue', 'get_setting', 'getSetting'] as $method) {
                if (!method_exists($model, $method)) {
                    continue;
                }
                try {
                    $value = $model->{$method}($key);
                    if (is_scalar($value) && (string) $value !== '') {
                        $settings[$key] = (string) $value;
                    }
                } catch (\Throwable $exception) {
                    // A missing optional setting must not expose an exception upstream.
                }
                break;
            }
        }

        return $this->settingsCache = $settings;
    }

    /** @return object|null */
    private function resolveSettingsModel()
    {
        if (is_object($this->settingsModel)) {
            return $this->settingsModel;
        }

        $class = 'Chatwoot_plugin\\Models\\Chat_settings_model';
        if (!class_exists($class, false)) {
            $path = dirname(__DIR__) . '/Models/Chat_settings_model.php';
            if (is_file($path)) {
                try {
                    require_once $path;
                } catch (\Throwable $exception) {
                    return null;
                }
            }
        }
        if (class_exists($class)) {
            try {
                $this->settingsModel = new $class();

                return $this->settingsModel;
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return null;
    }

    /** @return object|null */
    private function resolveCredentialCipher()
    {
        if (is_object($this->credentialCipher)) {
            return $this->credentialCipher;
        }

        $class = 'Chatwoot_plugin\\Libraries\\Credential_cipher';
        if (!class_exists($class, false)) {
            $path = __DIR__ . '/Credential_cipher.php';
            if (is_file($path)) {
                try {
                    require_once $path;
                } catch (\Throwable $exception) {
                    return null;
                }
            }
        }
        if (class_exists($class)) {
            try {
                $this->credentialCipher = new $class();

                return $this->credentialCipher;
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $source */
    private function decryptFirst(array $source, array $keys): string
    {
        $encrypted = $this->firstString($source, $keys);
        if ($encrypted === '') {
            return '';
        }

        $cipher = $this->resolveCredentialCipher();
        if ($cipher === null) {
            return '';
        }

        foreach (['decrypt', 'decrypt_value', 'decryptValue'] as $method) {
            if (!method_exists($cipher, $method)) {
                continue;
            }

            try {
                $value = $cipher->{$method}($encrypted);

                return is_scalar($value) ? trim((string) $value) : '';
            } catch (\Throwable $exception) {
                return '';
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>|object|string|null $instance
     * @return array<string, mixed>
     */
    private function normalizeInstanceOverride($instance): array
    {
        if ($instance === null) {
            return $this->instance;
        }

        if (is_string($instance)) {
            return array_merge($this->instance, ['evolution_instance_name' => $instance]);
        }

        return array_merge($this->instance, $this->toArray($instance));
    }

    /** @param array<string, mixed> $instance */
    private function instanceName(array $instance): string
    {
        return $this->firstString($instance, [
            'evolution_instance_name',
            'instance_name',
            'instance',
            'name',
            'internal_identifier',
        ]);
    }

    /** @param mixed $value */
    private function extractConnectionState($value): ?string
    {
        $data = $this->toArray($value);
        $paths = [
            ['instance', 'state'],
            ['instance', 'connectionStatus'],
            ['state'],
            ['connectionStatus'],
            ['connection_status'],
            ['status'],
            ['data', 'instance', 'state'],
            ['data', 'state'],
        ];

        foreach ($paths as $path) {
            $state = $this->pathValue($data, $path);
            if (is_scalar($state) && trim((string) $state) !== '') {
                return strtolower(trim((string) $state));
            }
        }

        return null;
    }

    /** @param mixed $value */
    private function findNestedKeyId($value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        if (isset($value['key']) && is_array($value['key']) && isset($value['key']['id']) && is_scalar($value['key']['id'])) {
            $id = trim((string) $value['key']['id']);
            if ($id !== '') {
                return $id;
            }
        }

        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }
            $id = $this->findNestedKeyId($child);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /** @param mixed $decoded */
    private function extractSafeError($decoded, string $apiKey): string
    {
        $data = $this->toArray($decoded);
        $paths = [
            ['message'],
            ['error', 'message'],
            ['error'],
            ['response', 'message'],
            ['errors', 0, 'message'],
        ];

        foreach ($paths as $path) {
            $value = $this->pathValue($data, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return $this->limitError((string) $this->sanitizer->redact((string) $value, [$apiKey]));
            }
            if (is_array($value) && $value !== []) {
                return $this->limitError($this->sanitizer->sanitize_to_json($value, [$apiKey]));
            }
        }

        return 'A Evolution API recusou a solicitacao.';
    }

    private function limitError(string $message): string
    {
        return strlen($message) > 500 ? substr($message, 0, 497) . '...' : $message;
    }

    private function normalizeNumber(string $number): string
    {
        $number = explode('@', $number, 2)[0];

        return (string) preg_replace('/\D+/', '', $number);
    }

    private function buildUrl(string $baseUrl, string $path): ?string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function safeEndpoint(string $path): string
    {
        $path = explode('?', $path, 2)[0];

        return strlen($path) > 200 ? substr($path, 0, 200) : $path;
    }

    /** @param array<string, mixed> $context */
    private function safeLog(string $level, string $message, array $context): void
    {
        $safeContext = $this->sanitizer->sanitize($context);

        if (is_callable($this->logger)) {
            try {
                call_user_func($this->logger, $level, $message, $safeContext);
            } catch (\Throwable $exception) {
                // Logging must never break message delivery.
            }

            return;
        }

        if (function_exists('log_message')) {
            log_message($level, $message . ' ' . $this->sanitizer->sanitize_to_json($safeContext));
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function success(int $statusCode, $data): array
    {
        return [
            'success' => true,
            'ok' => true,
            'status_code' => $statusCode,
            'http_status' => $statusCode,
            'data' => $data,
            'error' => null,
            'error_code' => null,
        ];
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function failure(string $code, string $message, int $statusCode = 0, $data = null): array
    {
        return [
            'success' => false,
            'ok' => false,
            'status_code' => $statusCode,
            'http_status' => $statusCode,
            'data' => $data,
            'error' => $message,
            'error_code' => $code,
        ];
    }

    /** @param mixed $value @return array<string, mixed> */
    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();

            return is_array($value) ? $value : [];
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    /** @param mixed $result @return array<string, mixed> */
    private function unwrapModelResult($result): array
    {
        if (is_object($result) && method_exists($result, 'getRow')) {
            try {
                $result = $result->getRow();
            } catch (\Throwable $exception) {
                return [];
            }
        }

        return $this->toArray($result);
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data @param array<int, int|string> $path @return mixed */
    private function pathValue(array $data, array $path)
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
