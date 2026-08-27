<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Chat_message_reactions_model
{
    private BaseConnection $db;
    private string $table;
    private string $changeTable;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect('default');
        if (strtolower((string) ($this->db->DBDriver ?? '')) === 'mysqli') $this->db->query('SET NAMES utf8mb4');
        $this->table = $this->db->prefixTable('chat_message_reactions');
        $this->changeTable = $this->db->prefixTable('chat_message_reaction_changes');
    }

    public function find_by_client_message_id(int $instanceId, string $clientMessageId): ?array
    {
        if ($instanceId < 1 || trim($clientMessageId) === '') return null;
        $row = $this->db->table($this->table)->where('instance_id', $instanceId)->where('client_message_id', trim($clientMessageId))->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }

    public function find_by_target_actor(int $messageId, string $reactorKey): ?array
    {
        if ($messageId < 1 || trim($reactorKey) === '') return null;
        $row = $this->db->table($this->table)->where('message_id', $messageId)->where('reactor_key', trim($reactorKey))->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }

    public function find_by_source_attempt_id(int $attemptId): ?array
    {
        if ($attemptId < 1) return null;
        $row = $this->db->table($this->table)
            ->where('source_attempt_id', $attemptId)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function upsert_confirmed_state(
        int $messageId,
        int $instanceId,
        string $provider,
        string $reactorKey,
        ?string $emoji,
        bool $fromMe,
        bool $active,
        ?string $providerEventId = null,
        ?string $providerTimestamp = null,
        ?int $sourceAttemptId = null,
        ?string $stateOrderAt = null,
        string $stateOrderKind = 'provider',
        ?string $stateOrderKey = null
    ): int
    {
        if ($messageId < 1 || $instanceId < 1 || trim($provider) === '' || trim($reactorKey) === '') throw new InvalidArgumentException('Reaction identity is incomplete.');
        $providerTimestamp = $this->normalizeTimestamp($providerTimestamp);
        $stateOrderKind = in_array($stateOrderKind, ['provider', 'provider_status', 'outbound', 'rollback'], true)
            ? $stateOrderKind
            : 'provider';
        $stateOrderKey = trim((string) ($stateOrderKey ?? $providerEventId ?? ''));
        $stateOrderAt = $this->normalizeOrderTimestamp($stateOrderAt ?? $providerTimestamp);
        $transactionOpen = false;
        try {
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Reaction state transaction could not begin.');
            }
            $transactionOpen = true;
            $existing = $this->find_by_target_actor($messageId, $reactorKey);
            if ($existing && !$this->acceptOrder($existing, $stateOrderAt, $stateOrderKind, $stateOrderKey)) {
                if (!$this->db->transCommit()) {
                    throw new RuntimeException('Reaction state transaction could not commit.');
                }
                $transactionOpen = false;
                return (int) $existing['id'];
            }
            $activeEmoji = $active ? (string) $emoji : null;
            if (($sourceAttemptId === null || $sourceAttemptId < 1) && $existing
                && (int) ($existing['source_attempt_id'] ?? 0) > 0
                && (int) ($existing['active'] ?? 0) === ($active ? 1 : 0)
                && (string) ($existing['emoji'] ?? '') === (string) ($activeEmoji ?? '')) {
                // A provider echo may confirm the same self state, but it does
                // not own the outbound attempt that established that state.
                $sourceAttemptId = (int) $existing['source_attempt_id'];
            }
            $changed = !$existing
                || (int) ($existing['active'] ?? 0) !== ($active ? 1 : 0)
                || (string) ($existing['emoji'] ?? '') !== (string) ($activeEmoji ?? '')
                || (int) ($existing['from_me'] ?? 0) !== ($fromMe ? 1 : 0);
            $payload = [
                'message_id' => $messageId,
                'instance_id' => $instanceId,
                'provider_name' => substr(trim($provider), 0, 32),
                'reactor_key' => substr(trim($reactorKey), 0, 191),
                'emoji' => $active ? (string) $emoji : null,
                'from_me' => $fromMe ? 1 : 0,
                'active' => $active ? 1 : 0,
                'provider_event_id' => $providerEventId !== null && trim($providerEventId) !== '' ? substr(trim($providerEventId), 0, 191) : null,
                'provider_timestamp' => $providerTimestamp,
                'source_attempt_id' => $sourceAttemptId !== null && $sourceAttemptId > 0 ? $sourceAttemptId : null,
                'state_order_at' => $stateOrderAt,
                'state_order_kind' => $stateOrderKind,
                'state_order_key' => $stateOrderKey !== '' ? substr($stateOrderKey, 0, 191) : null,
                // V011 columns are retained for compatibility, but outbound
                // attempt state and client identity live exclusively in V012.
                'send_state' => 'sent',
                'client_message_id' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'deleted' => 0,
            ];
            if ($existing) {
                if (!$this->db->table($this->table)->where('id', (int) $existing['id'])->update($payload)) {
                    throw new RuntimeException('Reaction state could not be persisted.');
                }
                $reactionId = (int) $existing['id'];
                if ($changed) $this->appendChange($reactionId, $messageId, $instanceId);
            } else {
                $payload['created_at'] = gmdate('Y-m-d H:i:s');
                if (!$this->db->table($this->table)->insert($payload)) {
                    throw new RuntimeException('Reaction state could not be inserted.');
                }
                $reactionId = (int) $this->db->insertID();
                if ($reactionId < 1) throw new RuntimeException('Reaction state insert returned no identity.');
                if ($changed) $this->appendChange($reactionId, $messageId, $instanceId);
            }
            if ($this->db->transStatus() === false || !$this->db->transCommit()) {
                throw new RuntimeException('Reaction state transaction could not commit.');
            }
            $transactionOpen = false;
            return $reactionId;
        } catch (Throwable $exception) {
            if ($transactionOpen) $this->db->transRollback();
            throw $exception;
        }
    }

    /** @deprecated Use upsert_confirmed_state; V011 is not outbound history. */
    public function upsert_state(int $messageId, int $instanceId, string $provider, string $reactorKey, ?string $emoji, bool $fromMe, bool $active, string $sendState = 'sent', ?string $clientMessageId = null, ?string $providerEventId = null): int
    {
        return $this->upsert_confirmed_state($messageId, $instanceId, $provider, $reactorKey, $emoji, $fromMe, $active, $providerEventId, null);
    }

    private function normalizeTimestamp(?string $timestamp): ?string
    {
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') return null;
        if (ctype_digit($timestamp)) $timestamp = gmdate('Y-m-d H:i:s', (int) $timestamp);
        $parsed = strtotime($timestamp);
        return $parsed === false ? null : gmdate('Y-m-d H:i:s', $parsed);
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

    private function acceptOrder(array $existing, ?string $incomingAt, string $incomingKind, string $incomingKey): bool
    {
        $existingAt = trim((string) ($existing['state_order_at'] ?? ''));
        $existingKind = (string) ($existing['state_order_kind'] ?? 'provider');
        $existingSource = (int) ($existing['source_attempt_id'] ?? 0);
        if ($incomingKind === 'outbound'
            && ctype_digit($incomingKey)
            && $existingSource > 0
            && (int) $incomingKey > $existingSource
            && in_array($existingKind, ['outbound', 'rollback'], true)) {
            // Local attempt ids disambiguate operations created in the same
            // database second. This also prevents a later outbound operation
            // from being blocked by a rollback timestamp generated moments
            // earlier than its persisted created_at value.
            return true;
        }
        if ($incomingAt === null) {
            // An incoming provider event without its own timestamp cannot
            // safely replace a state that already has ordering authority.
            return $existingAt === '';
        }
        if ($existingAt === '') return true;
        if ($incomingAt < $existingAt) return false;
        if ($incomingAt > $existingAt) return true;

        $priority = static fn (string $kind): int => match ($kind) {
            'rollback' => 4,
            'outbound' => 3,
            'provider_status' => 2,
            default => 1,
        };
        if ($priority($incomingKind) !== $priority($existingKind)) {
            // Outbound/rollback authority wins an equal-time provider echo.
            return $priority($incomingKind) > $priority($existingKind);
        }

        return $incomingKey !== '' && strcmp($incomingKey, (string) ($existing['state_order_key'] ?? '')) > 0;
    }

    private function appendChange(int $reactionId, int $messageId, int $instanceId): void
    {
        if ($reactionId < 1 || $messageId < 1 || $instanceId < 1) return;
        if (!$this->db->table($this->changeTable)->insert([
            'reaction_id' => $reactionId,
            'message_id' => $messageId,
            'instance_id' => $instanceId,
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])) {
            throw new RuntimeException('Reaction change cursor could not be advanced.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function aggregates_for_messages(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0)));
        if (!$messageIds) return [];
        $rows = $this->db->table($this->table)
            ->select('message_id, emoji, COUNT(*) AS reaction_count, MAX(from_me) AS reacted_by_me', false)
            ->whereIn('message_id', $messageIds)->where('active', 1)->where('deleted', 0)->where('emoji IS NOT NULL', null, false)
            ->groupBy(['message_id', 'emoji'])->orderBy('message_id', 'ASC')->get()->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['message_id'] ?? 0);
            if ($id < 1) continue;
            $result[$id][] = [
                'emoji' => mb_substr((string) ($row['emoji'] ?? ''), 0, 16),
                'count' => (int) ($row['reaction_count'] ?? 0),
                'reacted_by_me' => (int) ($row['reacted_by_me'] ?? 0) === 1,
            ];
        }
        return $result;
    }

    /** @return array<int> */
    public function target_ids_updated_after(int $conversationId, ?int $cursor): array
    {
        return $this->changes_after($conversationId, $cursor)['target_ids'];
    }

    public function latest_update_cursor(int $conversationId): int
    {
        return (int) $this->changes_after($conversationId, null)['cursor'];
    }

    /** @return array{target_ids:array<int>,cursor:int} */
    public function changes_after(int $conversationId, ?int $cursor): array
    {
        if ($conversationId < 1) return ['target_ids' => [], 'cursor' => max(0, (int) ($cursor ?? 0))];
        $messageTable = $this->db->protectIdentifiers($this->db->prefixTable('chat_messages'));
        $upperRow = $this->db->table($this->changeTable . ' c')
            ->select('MAX(c.id) AS latest_cursor', false)
            ->join($messageTable . ' m', 'm.id = c.message_id', 'inner', false)
            ->where('m.conversation_id', $conversationId)
            ->where('m.deleted', 0)
            ->get(1)
            ->getRowArray();
        $upper = max(0, (int) ($upperRow['latest_cursor'] ?? 0));
        if ($cursor === null || $cursor < 1) return ['target_ids' => [], 'cursor' => $upper];
        if ($upper <= $cursor) return ['target_ids' => [], 'cursor' => $cursor];

        $rows = $this->db->table($this->changeTable . ' c')
            ->select('DISTINCT c.message_id', false)
            ->join($messageTable . ' m', 'm.id = c.message_id', 'inner', false)
            ->where('m.conversation_id', $conversationId)
            ->where('m.deleted', 0)
            ->where('c.id >', $cursor)
            ->where('c.id <=', $upper)
            ->get()
            ->getResultArray();

        return [
            'target_ids' => array_values(array_filter(array_map(
                static fn (array $row): int => (int) ($row['message_id'] ?? 0),
                $rows
            ))),
            'cursor' => $upper,
        ];
    }
}
