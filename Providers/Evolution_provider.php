<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Providers;

use Chatwoot_plugin\Contracts\WhatsAppProviderInterface;
use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Services\Webhook_normalizer;
use RuntimeException;

class Evolution_provider implements WhatsAppProviderInterface
{
    public function __construct(
        private Evolution_client $client,
        private array $instance,
        private ?Chat_settings_model $settings = null
    ) {
        $this->settings ??= new Chat_settings_model();
    }

    public function name(): string { return 'evolution'; }
    public function capabilities(): array { return Provider_capabilities::evolution(); }
    public function getCapabilities(): array { return $this->capabilities(); }
    public function status(): array { return $this->client->status(); }
    public function testConnection(): array { return $this->status(); }
    public function normalizeWebhook(array $payload, array $context = []): array
    {
        $event = (new Webhook_normalizer())->normalize($payload);
        return $event === [] ? [] : [$event];
    }

    public function sendText(string $recipient, string $text, array $context = []): array
    {
        if (str_ends_with(strtolower(trim($recipient)), '@g.us')) {
            $endpoint = (string) $this->settings->get_value(Chat_settings_model::ENDPOINT_SEND_TEXT, '/message/sendText/{instance}');
            $instanceName = trim((string) ($this->instance['evolution_instance_name'] ?? ''));
            if ($instanceName === '') throw new RuntimeException('Instancia Evolution nao configurada.');
            $response = $this->client->post(str_replace('{instance}', rawurlencode($instanceName), $endpoint), ['number' => $recipient, 'text' => $text]);
            if (!empty($response['success']) && empty($response['message_id'])) $response['message_id'] = $this->client->extract_message_id($response['data'] ?? []);
            return $response;
        }
        return $this->client->send_text($recipient, $text);
    }

    public function sendMedia(string $recipient, array $media, array $context = []): array
    {
        $type = strtolower(trim((string) ($media['type'] ?? 'document')));
        $source = trim((string) ($media['data'] ?? $media['url'] ?? $media['link'] ?? ''));
        $mimeType = trim((string) ($media['mime_type'] ?? $media['mimeType'] ?? 'application/octet-stream'));
        $fileName = trim((string) ($media['filename'] ?? $media['file_name'] ?? ''));
        $caption = trim((string) ($media['caption'] ?? ''));
        if ($source === '') throw new RuntimeException('Conteudo da midia nao informado.');
        return $this->client->send_media($recipient, $source, $mimeType, $type, $fileName, $caption);
    }

    public function sendTemplate(string $recipient, string $templateName, string $languageCode, array $components = [], array $context = []): array
    {
        return ['success' => false, 'status_code' => 422, 'data' => [], 'error' => 'Templates oficiais nao sao suportados pela Evolution.', 'message_id' => ''];
    }

    public function listTemplates(int $limit = 250): array
    {
        return ['success' => false, 'status_code' => 422, 'data' => [], 'error' => 'Templates oficiais nao sao suportados pela Evolution.'];
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}
