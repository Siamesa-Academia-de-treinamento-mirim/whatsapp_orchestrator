<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Shared MySQL named-lock boundary for outbound idempotent sends.
 * A caller must release every acquired lock in a finally block.
 */
final class Send_lock_service
{
    public function __construct(private ?BaseConnection $db = null) {}

    public function nameFor(int $conversationId, string $clientMessageId): string
    {
        return 'chat_send_' . substr(hash('sha256', $conversationId . '|' . trim($clientMessageId)), 0, 40);
    }

    public function acquireFor(int $conversationId, string $clientMessageId, int $timeout = 0): bool
    {
        return $this->acquire($this->nameFor($conversationId, $clientMessageId), $timeout);
    }

    public function releaseFor(int $conversationId, string $clientMessageId): void
    {
        $this->release($this->nameFor($conversationId, $clientMessageId));
    }

    public function nameForReaction(int $instanceId, int $messageId, string $reactorKey): string
    {
        return 'chat_reaction_state_' . substr(hash('sha256', $instanceId . '|' . $messageId . '|' . trim($reactorKey)), 0, 40);
    }

    public function acquireReaction(int $instanceId, int $messageId, string $reactorKey, int $timeout = 0): bool
    {
        return $this->acquire($this->nameForReaction($instanceId, $messageId, $reactorKey), $timeout);
    }

    public function releaseReaction(int $instanceId, int $messageId, string $reactorKey): void
    {
        $this->release($this->nameForReaction($instanceId, $messageId, $reactorKey));
    }

    public function acquire(string $name, int $timeout = 0): bool
    {
        try {
            $db = $this->connection();
            $row = $db->query('SELECT GET_LOCK(?, ?) AS acquired_lock', [$name, max(0, $timeout)])->getRowArray();

            return (int) ($row['acquired_lock'] ?? 0) === 1;
        } catch (\Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not acquire outbound send lock.');
            return false;
        }
    }

    public function release(string $name): void
    {
        try {
            $this->connection()->query('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable $exception) {
            log_message('error', 'Chatwoot_plugin could not release outbound send lock.');
        }
    }

    private function connection(): BaseConnection
    {
        return $this->db ??= db_connect('default');
    }
}
