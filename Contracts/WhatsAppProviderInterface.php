<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Contracts;

interface WhatsAppProviderInterface
{
    public function name(): string;

    /** @return array<string,bool> */
    public function capabilities(): array;

    /** Stable public alias used by provider-neutral application code.
     * @return array<string,bool>
     */
    public function getCapabilities(): array;

    /** @return array<string,mixed> */
    public function status(): array;

    /** Stable public alias used by connection diagnostics.
     * @return array<string,mixed>
     */
    public function testConnection(): array;

    /** Normalize a provider webhook into one or more internal events.
     * @return array<int,array<string,mixed>>
     */
    public function normalizeWebhook(array $payload, array $context = []): array;

    /** @return array<string,mixed> */
    public function sendText(string $recipient, string $text, array $context = []): array;

    /** @return array<string,mixed> */
    public function sendMedia(string $recipient, array $media, array $context = []): array;

    /** @return array<string,mixed> */
    public function sendTemplate(string $recipient, string $templateName, string $languageCode, array $components = [], array $context = []): array;

    /** @return array<string,mixed> */
    public function listTemplates(int $limit = 250): array;

    public function verifySignature(string $rawBody, ?string $signature): bool;
}
