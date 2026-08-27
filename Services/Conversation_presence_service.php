<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_conversation_presence_model;
use Chatwoot_plugin\Models\Chat_conversations_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

/** Ephemeral staff presence with bounded TTL; it is not an audit stream. */
class Conversation_presence_service
{
    private BaseConnection $db;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?Chat_conversation_presence_model $presence = null,
        private ?Chat_conversations_model $conversations = null,
        ?BaseConnection $db = null,
        ?callable $clock = null
    ) {
        $this->presence ??= new Chat_conversation_presence_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->db = $db ?? db_connect('default');
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function touch(int $conversationId, int $userId, string $state): array
    {
        if (!$this->conversations->get_by_id($conversationId)) throw new RuntimeException('Conversa nao encontrada.', 404);
        if ($userId < 1) throw new RuntimeException('Agente nao autenticado.', 403);
        $state = strtolower(trim($state));
        if (!in_array($state, ['viewing', 'typing', 'leave'], true)) throw new InvalidArgumentException('Estado de presenca invalido.');
        $now = $this->now();
        $lastSeen = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + ($state === 'leave' ? 0 : 45));
        $table = $this->db->prefixTable('chat_conversation_presence');
        $typingUntil = $state === 'typing' ? gmdate('Y-m-d H:i:s', $now + 8) : null;
        $sql = "INSERT INTO {$table} (conversation_id, user_id, viewing, typing_until, last_seen_at, expires_at, updated_at, deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                viewing = VALUES(viewing),
                last_seen_at = VALUES(last_seen_at),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at),
                deleted = 0";
        $bindings = [$conversationId, $userId, $state === 'leave' ? 0 : 1, $typingUntil, $lastSeen, $expiresAt, $lastSeen];
        if ($state === 'typing' || $state === 'leave') {
            $sql = str_replace(
                "updated_at = VALUES(updated_at),\n                deleted = 0",
                "updated_at = VALUES(updated_at),\n                deleted = 0,\n                typing_until = VALUES(typing_until)",
                $sql
            );
        }
        $this->db->query($sql, $bindings);
        return $this->list($conversationId);
    }

    public function list(int $conversationId): array
    {
        if (!$this->conversations->get_by_id($conversationId)) throw new RuntimeException('Conversa nao encontrada.', 404);
        $this->expire($conversationId);
        $nowTimestamp = $this->now();
        $now = gmdate('Y-m-d H:i:s', $nowTimestamp);
        $rows = $this->db->table('chat_conversation_presence p')
            ->select('p.user_id, p.viewing, p.typing_until, p.last_seen_at, u.first_name, u.last_name')
            ->join('users u', 'u.id = p.user_id', 'inner')
            ->where('p.conversation_id', $conversationId)
            ->where('p.deleted', 0)
            ->where('p.expires_at >', $now)
            ->where('u.user_type', 'staff')
            ->where('u.status', 'active')
            ->where('u.deleted', 0)
            ->orderBy('u.first_name', 'ASC')
            ->get()->getResultArray();
        return [
            'conversation_id' => $conversationId,
            'agents' => array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: 'Agente',
                'avatar' => '',
                'viewing' => !empty($row['viewing']),
                'typing' => !empty($row['typing_until']) && strtotime((string) $row['typing_until'] . ' UTC') > $nowTimestamp,
                'last_seen_at' => $row['last_seen_at'] ? gmdate('c', strtotime((string) $row['last_seen_at'] . ' UTC')) : null,
            ], $rows),
        ];
    }

    private function expire(int $conversationId): void
    {
        $now = gmdate('Y-m-d H:i:s', $this->now());
        $this->db->table('chat_conversation_presence')
            ->where('conversation_id', $conversationId)
            ->where('deleted', 0)
            ->where('typing_until <=', $now)
            ->where('expires_at >', $now)
            ->update(['typing_until' => null, 'updated_at' => $now]);
        $this->db->table('chat_conversation_presence')
            ->where('conversation_id', $conversationId)
            ->where('deleted', 0)
            ->where('expires_at <=', $now)
            ->update(['viewing' => 0, 'typing_until' => null, 'expires_at' => $now, 'updated_at' => $now]);
    }

    private function now(): int
    {
        return max(0, (int) call_user_func($this->clock));
    }
}
