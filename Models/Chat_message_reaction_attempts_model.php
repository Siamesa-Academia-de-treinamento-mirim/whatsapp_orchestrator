<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Persistence for outbound reaction attempts; never a projection of V011. */
class Chat_message_reaction_attempts_model
{
    private const STATES = [
        'awaiting_provider',
        'sent',
        'retryable_failure',
        'ambiguous_failure',
        'rejected',
    ];

    private BaseConnection $db;
    private string $table;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect('default');
        if (strtolower((string) ($this->db->DBDriver ?? '')) === 'mysqli') $this->db->query('SET NAMES utf8mb4');
        $this->table = $this->db->prefixTable('chat_message_reaction_attempts');
    }

    public function find_by_client_message_id(int $instanceId, string $clientMessageId): ?array
    {
        if ($instanceId < 1 || trim($clientMessageId) === '') return null;

        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('client_message_id', trim($clientMessageId))
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function find_by_id(int $id): ?array
    {
        if ($id < 1) return null;
        $row = $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->get(1)->getRowArray();

        return $row ?: null;
    }

    public function find_by_provider_event_id(int $instanceId, string $providerEventId): ?array
    {
        $providerEventId = trim($providerEventId);
        if ($instanceId < 1 || $providerEventId === '') return null;
        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('provider_event_id', $providerEventId)
            ->where('deleted', 0)
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function create(
        int $messageId,
        int $instanceId,
        string $provider,
        string $clientMessageId,
        ?string $emoji,
        bool $active,
        string $state = 'awaiting_provider',
        ?int $actorUserId = null
    ): int {
        if ($messageId < 1 || $instanceId < 1 || trim($provider) === '' || trim($clientMessageId) === '') {
            throw new InvalidArgumentException('Reaction attempt identity is incomplete.');
        }
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Reaction attempt state is invalid.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($this->table)->insert([
            'message_id' => $messageId,
            'instance_id' => $instanceId,
            'provider_name' => substr(trim($provider), 0, 32),
            'client_message_id' => substr(trim($clientMessageId), 0, 191),
            'requested_emoji' => $active && $emoji !== null && trim($emoji) !== '' ? substr($emoji, 0, 32) : null,
            'requested_active' => $active ? 1 : 0,
            'send_state' => $state,
            'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted' => 0,
        ]);

        return (int) $this->db->insertID();
    }

    public function update_state(int $id, string $state, ?string $providerEventId = null): bool
    {
        if ($id < 1 || !in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Reaction attempt update is invalid.');
        }

        $payload = [
            'send_state' => $state,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($providerEventId !== null && trim($providerEventId) !== '') {
            $payload['provider_event_id'] = substr(trim($providerEventId), 0, 191);
        }

        return (bool) $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->update($payload);
    }

    public function set_previous_state(
        int $id,
        ?string $emoji,
        bool $active,
        bool $fromMe = true,
        ?int $sourceAttemptId = null
    ): bool {
        if ($id < 1) return false;

        return (bool) $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->update([
            'previous_emoji' => $active && $emoji !== null && trim($emoji) !== '' ? substr($emoji, 0, 32) : null,
            'previous_active' => $active ? 1 : 0,
            'previous_from_me' => $fromMe ? 1 : 0,
            'previous_source_attempt_id' => $sourceAttemptId !== null && $sourceAttemptId > 0 ? $sourceAttemptId : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function update_provider_status(
        int $id,
        string $providerStatus,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $providerTimestamp,
        string $sendState,
        ?string $providerEventId = null
    ): bool {
        $attempt = $this->find_by_id($id);
        if (!$attempt) return false;
        $providerStatus = strtolower(trim($providerStatus));
        if (!in_array($providerStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            throw new InvalidArgumentException('Reaction provider status is invalid.');
        }
        $providerAt = $this->normalizeOrderTimestamp($providerTimestamp);
        $existingAt = $this->normalizeOrderTimestamp((string) ($attempt['provider_status_at'] ?? ''));
        if ($existingAt !== null && $providerAt === null) return true;
        if ($existingAt !== null && $providerAt !== null && $providerAt < $existingAt) return true;
        if ($existingAt !== null && $providerAt !== null && $providerAt === $existingAt) {
            $rank = static fn (string $status): int => ['sent' => 10, 'delivered' => 20, 'read' => 30, 'failed' => 40][$status] ?? 0;
            if ($rank($providerStatus) < $rank((string) ($attempt['provider_status'] ?? ''))) return true;
        }
        if (in_array((string) ($attempt['provider_status'] ?? ''), ['delivered', 'read'], true) && $providerStatus === 'failed') {
            return true;
        }

        $currentState = (string) ($attempt['send_state'] ?? '');
        if ($currentState === 'rejected' && $providerStatus !== 'failed') {
            // A permanent provider rejection is terminal. A late receipt is
            // not enough authority to turn it into a successful attempt.
            return true;
        }
        if ($currentState === 'rejected' && $sendState !== 'rejected') {
            return true;
        }

        $payload = [
            'provider_status' => $providerStatus,
            'provider_error_code' => $errorCode !== null && trim($errorCode) !== '' ? substr(trim($errorCode), 0, 64) : null,
            'provider_error_message' => $errorMessage !== null && trim($errorMessage) !== '' ? mb_substr(trim($errorMessage), 0, 1000) : null,
            'provider_status_at' => $providerAt,
            'send_state' => $sendState,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($providerEventId !== null && trim($providerEventId) !== '') {
            $payload['provider_event_id'] = substr(trim($providerEventId), 0, 191);
        }

        return (bool) $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->update($payload);
    }

    private function normalizeOrderTimestamp(?string $timestamp): ?string
    {
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') return null;
        if (ctype_digit($timestamp)) $timestamp = gmdate('Y-m-d H:i:s.u', (int) $timestamp);
        try {
            return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
