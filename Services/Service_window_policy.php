<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Providers\Provider_capabilities;

/**
 * Provider-neutral service-window authority. Meta's customer-service window
 * is deliberately a fixed 24 hours; configuration values cannot shorten or
 * extend it.
 */
final class Service_window_policy
{
    public const META_WINDOW_SECONDS = 86400;

    private const CUSTOMER_MESSAGE_TYPES = [
        'text', 'image', 'audio', 'voice', 'video', 'document', 'sticker',
        'location', 'contact', 'contacts', 'interactive', 'order',
    ];

    public function state(array $conversation, array $capabilities, ?int $now = null): array
    {
        $now ??= time();
        $required = !empty($capabilities['conversation']['service_window']);
        $lastCustomer = $this->timestamp($conversation['last_customer_message_at'] ?? null);
        if (!$required) {
            return [
                'required' => false,
                'open' => true,
                'expires_at' => null,
                'last_customer_message_at' => $this->iso($lastCustomer),
                'seconds_remaining' => null,
                'freeform_allowed' => true,
                'template_required' => false,
            ];
        }

        // The persisted expiry is a legacy/cache field.  Meta's customer
        // care window is an authority derived from the last valid customer
        // event and must not be shortened or extended by old settings or a
        // stale write.
        $expiresAt = $lastCustomer !== null ? $lastCustomer + self::META_WINDOW_SECONDS : null;
        $open = $expiresAt !== null && $expiresAt > $now;
        return [
            'required' => true,
            'open' => $open,
            'expires_at' => $this->iso($expiresAt),
            'last_customer_message_at' => $this->iso($lastCustomer),
            'seconds_remaining' => $open ? max(0, $expiresAt - $now) : 0,
            'freeform_allowed' => $open,
            'template_required' => !$open,
        ];
    }

    public function assertFreeformAllowed(array $conversation, array $capabilities, string $kind = 'mensagem'): array
    {
        $state = $this->state($conversation, $capabilities);
        if (!empty($state['freeform_allowed'])) return $state;

        throw new Message_send_exception(
            'A janela de atendimento oficial esta encerrada. Envie um template aprovado antes da ' . $kind . '.',
            'rejected',
            409,
            null,
            'SERVICE_WINDOW_CLOSED',
            [
                'expires_at' => $state['expires_at'],
                'template_required' => true,
                'service_window' => $state,
            ]
        );
    }

    public function isValidCustomerMessage(array $normalized, bool $fromMe): bool
    {
        if ($fromMe || !empty($normalized['is_system']) || !empty($normalized['is_internal_note'])) return false;
        $providerType = strtolower(trim((string) ($normalized['provider_message_type'] ?? $normalized['raw_provider_type'] ?? '')));
        if (!empty($normalized['is_customer_message']) && in_array($providerType, self::CUSTOMER_MESSAGE_TYPES, true)) return true;
        $type = strtolower(trim((string) ($normalized['message_type'] ?? $normalized['type'] ?? '')));
        return in_array($type, self::CUSTOMER_MESSAGE_TYPES, true) && $type !== 'order';
    }

    /** Return fields that advance the customer window only monotonically. */
    public function customerWindowData(array $existingConversation, array $normalized, bool $fromMe, int $timestamp, string $provider): array
    {
        if (!$this->isValidCustomerMessage($normalized, $fromMe) || $timestamp < 1) return [];

        $previous = $this->timestamp($existingConversation['last_customer_message_at'] ?? null);
        if ($previous !== null && $timestamp <= $previous) return [];

        $capabilities = Provider_capabilities::forProvider($provider);
        $data = ['last_customer_message_at' => gmdate('Y-m-d H:i:s', $timestamp)];
        if (!empty($capabilities['conversation']['service_window'])) {
            $data['service_window_expires_at'] = gmdate('Y-m-d H:i:s', $timestamp + self::META_WINDOW_SECONDS);
        }
        return $data;
    }

    public function timestamp($value): ?int
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }
        $value = trim((string) $value);
        if ($value === '') return null;
        $timestamp = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
        return $timestamp === false ? null : $timestamp;
    }

    private function iso(?int $timestamp): ?string
    {
        return $timestamp !== null ? gmdate('c', $timestamp) : null;
    }
}
