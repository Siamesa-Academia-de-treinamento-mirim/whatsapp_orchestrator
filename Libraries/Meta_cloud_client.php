<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use Chatwoot_plugin\Services\Payload_sanitizer;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Minimal, dependency-free client for the official WhatsApp Cloud API. */
class Meta_cloud_client
{
    /** @var callable|null */
    private $transport;
    private Payload_sanitizer $sanitizer;
    private string $phoneNumberId;
    private string $wabaId;
    private string $accessToken;
    private string $appSecret;
    private string $graphVersion;
    private int $timeout;

    public function __construct(array $config, ?callable $transport = null, ?Payload_sanitizer $sanitizer = null)
    {
        $this->phoneNumberId = trim((string) ($config['phone_number_id'] ?? ''));
        $this->wabaId = trim((string) ($config['waba_id'] ?? ''));
        $this->accessToken = trim((string) ($config['access_token'] ?? ''));
        $this->appSecret = trim((string) ($config['app_secret'] ?? ''));
        $version = strtolower(trim((string) ($config['graph_version'] ?? 'v25.0')));
        if (!preg_match('/^v\d{1,2}\.0$/', $version)) throw new InvalidArgumentException('Versao da Graph API invalida.');
        $this->graphVersion = $version;
        $this->timeout = min(120, max(3, (int) ($config['timeout'] ?? 30)));
        $this->transport = $transport;
        $this->sanitizer = $sanitizer ?? new Payload_sanitizer();
    }

    public function configured(): bool
    {
        return $this->phoneNumberId !== '' && $this->accessToken !== '';
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        if (!$this->configured()) return $this->failure(0, 'Credenciais da API oficial incompletas.');
        $response = $this->request('GET', '/' . rawurlencode($this->phoneNumberId), null, [
            'fields' => 'display_phone_number,verified_name,quality_rating,name_status',
        ]);
        $response['connection_status'] = !empty($response['success']) ? 'connected' : 'error';
        $response['state'] = !empty($response['success']) ? 'open' : 'error';
        return $response;
    }

    /** @return array<string,mixed> */
    public function sendText(string $recipient, string $text, array $context = []): array
    {
        $recipient = $this->normalizeRecipient($recipient);
        $text = trim($text);
        if ($recipient === '' || $text === '') throw new InvalidArgumentException('Destinatario e texto sao obrigatorios.');
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ];
        $this->appendReplyContext($payload, $context);
        return $this->messageRequest($payload);
    }

    /** @return array<string,mixed> */
    public function sendTemplate(string $recipient, string $templateName, string $languageCode, array $components = []): array
    {
        $recipient = $this->normalizeRecipient($recipient);
        $templateName = trim($templateName);
        $languageCode = trim($languageCode);
        if ($recipient === '' || $templateName === '' || $languageCode === '') throw new InvalidArgumentException('Destinatario, template e idioma sao obrigatorios.');
        $template = ['name' => $templateName, 'language' => ['code' => $languageCode]];
        if ($components) $template['components'] = $this->sanitizeTemplateComponents($components);
        return $this->messageRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /** @return array<string,mixed> */
    public function sendMedia(string $recipient, array $media, array $context = []): array
    {
        $recipient = $this->normalizeRecipient($recipient);
        $type = strtolower(trim((string) ($media['type'] ?? '')));
        if (!in_array($type, ['image', 'audio', 'video', 'document'], true)) throw new InvalidArgumentException('Tipo de midia nao suportado pela API oficial.');
        $node = [];
        if (!empty($media['id'])) $node['id'] = trim((string) $media['id']);
        elseif (!empty($media['link'])) $node['link'] = trim((string) $media['link']);
        else throw new InvalidArgumentException('A midia precisa de id ou link HTTPS.');
        if ($type === 'audio' && !empty($media['voice_note'])) $node['voice'] = true;
        if (!empty($media['caption']) && $type !== 'audio') $node['caption'] = mb_substr(trim((string) $media['caption']), 0, 1024);
        if (!empty($media['filename']) && $type === 'document') $node['filename'] = mb_substr(trim((string) $media['filename']), 0, 255);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => $type,
            $type => $node,
        ];
        $this->appendReplyContext($payload, $context);
        return $this->messageRequest($payload);
    }

    /** @return array<string,mixed> */
    public function sendReaction(string $recipient, string $messageId, string $emoji, array $context = []): array
    {
        $recipient = $this->normalizeRecipient($recipient);
        $messageId = trim($messageId);
        $emoji = trim($emoji);
        if ($recipient === '' || $messageId === '' || mb_strlen($emoji) > 16) throw new InvalidArgumentException('Destinatario, mensagem alvo e emoji sao obrigatorios ou validos.');
        return $this->messageRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'reaction',
            'reaction' => ['message_id' => $messageId, 'emoji' => $emoji],
        ]);
    }

    /** @return array<string,mixed> */
    public function listTemplates(int $limit = 250): array
    {
        return $this->listTemplatesPage($limit, null);
    }

    /** @return array<string,mixed> */
    public function listTemplatesPage(int $limit = 250, ?string $after = null): array
    {
        if ($this->wabaId === '') throw new RuntimeException('WABA ID nao configurado.');
        $query = [
            'limit' => min(250, max(1, $limit)),
            'fields' => 'id,name,status,category,language,components,quality_score,rejected_reason',
        ];
        if ($after !== null && preg_match('/^[A-Za-z0-9._:=-]{1,512}$/', $after)) {
            $query['after'] = $after;
        } elseif ($after !== null) {
            throw new InvalidArgumentException('Cursor de templates Meta invalido.');
        }
        return $this->request('GET', '/' . rawurlencode($this->wabaId) . '/message_templates', null, $query);
    }

    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        if ($this->appSecret === '') return false;
        $signatureHeader = trim((string) $signatureHeader);
        if (!str_starts_with($signatureHeader, 'sha256=')) return false;
        $provided = substr($signatureHeader, 7);
        return strlen($provided) === 64 && hash_equals(hash_hmac('sha256', $rawBody, $this->appSecret), strtolower($provided));
    }

    /** @return array<string,mixed> */
    private function messageRequest(array $payload): array
    {
        $response = $this->request('POST', '/' . rawurlencode($this->phoneNumberId) . '/messages', $payload);
        if (!empty($response['success'])) {
            $response['message_id'] = (string) ($response['data']['messages'][0]['id'] ?? '');
        }
        return $response;
    }

    /**
     * Keep local media/history metadata outside the Meta transport boundary.
     * The Cloud API accepts only the documented component and parameter keys.
     *
     * @param array<int,mixed> $components
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeTemplateComponents(array $components): array
    {
        $result = [];
        foreach ($components as $component) {
            if (!is_array($component)) throw new InvalidArgumentException('Componente de template Meta invalido.');
            $type = strtolower(trim((string) ($component['type'] ?? '')));
            if (!in_array($type, ['header', 'body', 'button'], true)) throw new InvalidArgumentException('Tipo de componente de template Meta nao suportado.');
            if (!is_array($component['parameters'] ?? null)) throw new InvalidArgumentException('Parametros de template Meta invalidos.');

            $clean = ['type' => $type];
            if ($type === 'button') {
                $subType = strtolower(trim((string) ($component['sub_type'] ?? '')));
                $index = (string) ($component['index'] ?? '');
                if ($subType !== '') $clean['sub_type'] = $subType;
                if ($index !== '' && preg_match('/^\d{1,3}$/', $index)) $clean['index'] = $index;
                elseif ($index !== '') throw new InvalidArgumentException('Indice de botao Meta invalido.');
            }
            $parameters = [];
            foreach ($component['parameters'] as $parameter) {
                if (!is_array($parameter)) throw new InvalidArgumentException('Parametro de template Meta invalido.');
                $parameterType = strtolower(trim((string) ($parameter['type'] ?? '')));
                if ($parameterType === 'text') {
                    if (!array_key_exists('text', $parameter) || !is_scalar($parameter['text'])) throw new InvalidArgumentException('Texto de parametro Meta invalido.');
                    $parameters[] = ['type' => 'text', 'text' => (string) $parameter['text']];
                    continue;
                }
                if (!in_array($parameterType, ['image', 'video', 'document'], true)) throw new InvalidArgumentException('Tipo de parametro Meta nao suportado.');
                $node = is_array($parameter[$parameterType] ?? null) ? $parameter[$parameterType] : [];
                $link = trim((string) ($node['link'] ?? ''));
                $id = trim((string) ($node['id'] ?? ''));
                if (($link === '') === ($id === '')) throw new InvalidArgumentException('A midia de template Meta precisa de link ou id.');
                if ($link !== '') {
                    if (!str_starts_with(strtolower($link), 'https://') || filter_var($link, FILTER_VALIDATE_URL) === false) throw new InvalidArgumentException('O link de midia do template Meta precisa ser HTTPS valido.');
                    $parameters[] = ['type' => $parameterType, $parameterType => ['link' => $link]];
                } else {
                    if (!preg_match('/^[A-Za-z0-9._:-]{1,512}$/', $id)) throw new InvalidArgumentException('O id de midia do template Meta e invalido.');
                    $parameters[] = ['type' => $parameterType, $parameterType => ['id' => $id]];
                }
            }
            $clean['parameters'] = $parameters;
            $result[] = $clean;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        if (!$this->configured()) return $this->failure(0, 'Credenciais da API oficial incompletas.');
        if (!str_starts_with($path, '/') || str_contains($path, '..')) throw new InvalidArgumentException('Caminho Graph API invalido.');
        $url = 'https://graph.facebook.com/' . $this->graphVersion . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->accessToken];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        try {
            $raw = $this->execute($method, $url, $headers, $body);
        } catch (Throwable $exception) {
            return $this->failure(0, 'Falha segura ao comunicar com a API oficial.');
        }
        $status = (int) ($raw['status_code'] ?? 0);
        $decoded = json_decode((string) ($raw['body'] ?? ''), true);
        if (!is_array($decoded)) $decoded = [];
        $success = $status >= 200 && $status < 300 && empty($raw['error']);
        $error = null;
        if (!$success) {
            $error = trim((string) ($decoded['error']['message'] ?? 'A API oficial nao confirmou a operacao.'));
            $error = mb_substr($error, 0, 500);
        }
        return [
            'success' => $success,
            'status_code' => $status,
            'data' => $this->sanitizer->redact($decoded, [$this->accessToken, $this->appSecret]),
            'error' => $error,
            'error_code' => $success ? null : ($decoded['error']['code'] ?? null),
            'error_type' => $success ? null : ($decoded['error']['type'] ?? null),
        ];
    }

    /** @return array{status_code:int,body:mixed,error:bool} */
    private function execute(string $method, string $url, array $headers, string $body): array
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $method, $url, $headers, $body, ['timeout' => $this->timeout]);
            return is_array($result) ? $result : ['status_code' => 0, 'body' => null, 'error' => true];
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== '') curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($curl);
        $error = curl_errno($curl) !== 0;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status_code' => $status, 'body' => $response === false ? null : $response, 'error' => $error];
    }

    private function normalizeRecipient(string $recipient): string
    {
        if (str_contains($recipient, '@')) $recipient = explode('@', $recipient, 2)[0];
        return preg_replace('/\D+/', '', $recipient) ?: '';
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context */
    private function appendReplyContext(array &$payload, array $context): void
    {
        $externalId = trim((string) ($context['reply_to_external_message_id'] ?? ''));
        if ($externalId !== '') {
            $payload['context'] = ['message_id' => $externalId];
        }
    }

    /** @return array<string,mixed> */
    private function failure(int $status, string $error): array
    {
        return ['success' => false, 'status_code' => $status, 'data' => [], 'error' => $error, 'error_code' => null, 'message_id' => ''];
    }
}
