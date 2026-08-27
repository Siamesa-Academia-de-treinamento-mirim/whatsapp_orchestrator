<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Providers;

use Chatwoot_plugin\Contracts\WhatsAppProviderInterface;
use Chatwoot_plugin\Libraries\Meta_cloud_client;
use Chatwoot_plugin\Services\Meta_webhook_normalizer;
use Chatwoot_plugin\Services\Media_policy_service;
use InvalidArgumentException;

class Meta_cloud_provider implements WhatsAppProviderInterface
{
    public function __construct(private Meta_cloud_client $client) {}
    public function name(): string { return 'meta_cloud'; }
    public function capabilities(): array { return Provider_capabilities::metaCloud(); }
    public function getCapabilities(): array { return $this->capabilities(); }
    public function status(): array { return $this->client->status(); }
    public function testConnection(): array { return $this->status(); }
    public function normalizeWebhook(array $payload, array $context = []): array
    {
        $identifier = trim((string) ($context['instance_identifier'] ?? $context['instance_name'] ?? ''));
        if ($identifier === '') {
            throw new InvalidArgumentException('Identificador interno da instancia Meta nao informado.');
        }
        return (new Meta_webhook_normalizer())->expand($payload, $identifier);
    }
    public function sendText(string $recipient, string $text, array $context = []): array { return $this->client->sendText($recipient, $text, $context); }
    public function sendMedia(string $recipient, array $media, array $context = []): array
    {
        (new Media_policy_service())->validatePayload($media, $this->capabilities());
        return $this->client->sendMedia($recipient, $media, $context);
    }
    public function sendReaction(string $recipient, string $messageId, string $emoji, array $context = []): array { return $this->client->sendReaction($recipient, $messageId, $emoji, $context); }
    public function sendTemplate(string $recipient, string $templateName, string $languageCode, array $components = [], array $context = []): array { return $this->client->sendTemplate($recipient, $templateName, $languageCode, $components); }
    public function listTemplates(int $limit = 250): array { return $this->client->listTemplates($limit); }
    public function listTemplatesPage(int $limit = 250, ?string $after = null): array { return $this->client->listTemplatesPage($limit, $after); }
    public function verifySignature(string $rawBody, ?string $signature): bool { return $this->client->verifySignature($rawBody, $signature); }
}
