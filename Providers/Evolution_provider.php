<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Providers;

use Chatwoot_plugin\Contracts\WhatsAppProviderInterface;
use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Services\Media_policy_service;
use Chatwoot_plugin\Services\Webhook_normalizer;
use RuntimeException;

class Evolution_provider implements WhatsAppProviderInterface
{
    public function __construct(
        private Evolution_client $client,
        private array $instance,
        private ?Chat_settings_model $settings = null
    ) {
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
            $settings = $this->settings ??= new Chat_settings_model();
            $endpoint = (string) $settings->get_value(Chat_settings_model::ENDPOINT_SEND_TEXT, '/message/sendText/{instance}');
            $instanceName = trim((string) ($this->instance['evolution_instance_name'] ?? ''));
            if ($instanceName === '') throw new RuntimeException('Instancia Evolution nao configurada.');
            $payload = ['number' => $recipient, 'text' => $text];
            $quoted = $this->quotedPayload($context);
            if ($quoted !== null) $payload['quoted'] = $quoted;
            $response = $this->client->post(str_replace('{instance}', rawurlencode($instanceName), $endpoint), $payload);
            if (!empty($response['success']) && empty($response['message_id'])) $response['message_id'] = $this->client->extract_message_id($response['data'] ?? []);
            return $response;
        }
        $replyId = trim((string) ($context['reply_to_external_message_id'] ?? ''));
        if ($replyId !== '' && method_exists($this->client, 'send_text_with_context')) {
            return $this->client->send_text_with_context($recipient, $text, null, $context);
        }
        return $this->client->send_text($recipient, $text);
    }

    public function sendMedia(string $recipient, array $media, array $context = []): array
    {
        (new Media_policy_service())->validatePayload($media, $this->capabilities());
        $type = strtolower(trim((string) ($media['type'] ?? 'document')));
        $source = trim((string) ($media['data'] ?? $media['url'] ?? $media['link'] ?? ''));
        $mimeType = trim((string) ($media['mime_type'] ?? $media['mimeType'] ?? 'application/octet-stream'));
        $fileName = trim((string) ($media['filename'] ?? $media['file_name'] ?? ''));
        $caption = trim((string) ($media['caption'] ?? ''));
        if ($source === '') throw new RuntimeException('Conteudo da midia nao informado.');
        return $this->client->send_media($recipient, $source, $mimeType, $type, $fileName, $caption, null, array_merge($context, [
            'voice_note' => !empty($media['voice_note']) || !empty($context['voice_note']),
        ]));
    }

    public function sendReaction(string $recipient, string $messageId, string $emoji, array $context = []): array
    {
        $instanceName = trim((string) ($this->instance['evolution_instance_name'] ?? ''));
        if ($instanceName === '') throw new RuntimeException('Instancia Evolution nao configurada.');
        return $this->client->sendReaction($recipient, $messageId, $emoji, null, [
            'remote_jid' => $context['target_remote_jid'] ?? $recipient,
            'from_me' => !empty($context['target_from_me']),
            'participant' => $context['target_participant_jid'] ?? ($context['target_sender_jid'] ?? ''),
        ]);
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

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function quotedPayload(array $context): ?array
    {
        $id = trim((string) ($context['reply_to_external_message_id'] ?? ''));
        if ($id === '') return null;
        return ['key' => [
            'remoteJid' => trim((string) ($context['reply_to_remote_jid'] ?? '')),
            'fromMe' => !empty($context['reply_to_from_me']),
            'id' => $id,
        ]];
    }
}
