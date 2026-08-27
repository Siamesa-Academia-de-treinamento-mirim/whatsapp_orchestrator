<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Canonical conversation workflow rules and the due-snooze worker. */
class Conversation_workflow_service
{
    public const STATUSES = ['open', 'pending', 'resolved', 'snoozed'];
    public const PRIORITIES = ['none', 'low', 'medium', 'high', 'urgent'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?Audit_service $audit = null)
    {
        $this->db = $db ?? db_connect('default');
        $this->audit ??= new Audit_service();
    }

    public static function canonicalPriority($value): string
    {
        if (is_bool($value)) {
            return $value ? 'high' : 'medium';
        }

        $priority = strtolower(trim((string) $value));
        if ($priority === '' || $priority === 'none') {
            return 'none';
        }
        if ($priority === 'normal') {
            return 'medium';
        }
        return in_array($priority, self::PRIORITIES, true) ? $priority : 'none';
    }

    public static function validatePriority($value): string
    {
        if (is_bool($value)) {
            return $value ? 'high' : 'medium';
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === 'normal') {
            return 'medium';
        }
        if (!in_array($raw, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Prioridade invalida.');
        }
        return $raw;
    }

    public static function validateStatus(string $value): string
    {
        $status = strtolower(trim($value));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Status de conversa invalido.');
        }
        return $status;
    }

    public static function snoozeUntil(string $value, ?DateTimeImmutable $now = null): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Informe uma data futura para o snooze.');
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Data de snooze invalida.', 0, $exception);
        }

        $utc = $date->setTimezone(new DateTimeZone('UTC'));
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $max = $now->modify('+1 year');
        if ($utc <= $now || $utc > $max) {
            throw new InvalidArgumentException('O snooze deve estar no futuro e em ate um ano.');
        }

        return $utc->format('Y-m-d H:i:s');
    }

    /** @return array{woken:int} */
    public function wakeDueSnoozes(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');
        $rows = $this->db->table('chat_conversations')
            ->select('id,instance_id,status,snoozed_until')
            ->where('deleted', 0)
            ->where('status', 'snoozed')
            ->where('snoozed_until <=', $nowSql)
            ->get()
            ->getResultArray();

        $woken = 0;
        foreach ($rows as $row) {
            $updated = $this->db->table('chat_conversations')
                ->where('id', (int) $row['id'])
                ->where('deleted', 0)
                ->where('status', 'snoozed')
                ->where('snoozed_until <=', $nowSql)
                ->update([
                    'status' => 'open',
                    'snoozed_until' => null,
                    'snoozed_by' => null,
                    'updated_at' => $nowSql,
                ]);
            if (!$updated) {
                continue;
            }
            $woken++;
            $this->audit->record(null, 'conversation.opened', 'conversation', (int) $row['id'], (int) ($row['instance_id'] ?? 0), ['status' => 'snoozed'], ['status' => 'open', 'reason' => 'snooze_due']);
        }

        return ['woken' => $woken];
    }

    /** @return array{staff:array<int,array<string,mixed>>,teams:array<int,array<string,mixed>>} */
    public function assignmentOptions(): array
    {
        $staff = $this->db->table('users')
            ->select('id,first_name,last_name,image')
            ->where('user_type', 'staff')
            ->where('status', 'active')
            ->where('deleted', 0)
            ->orderBy('first_name', 'ASC')
            ->orderBy('last_name', 'ASC')
            ->get()
            ->getResultArray();
        $staff = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'avatar' => (string) ($row['image'] ?? ''),
            ];
        }, $staff);

        $teams = [];
        try {
            $teams = $this->db->table('team')
                ->select('id,title,members')
                ->where('deleted', 0)
                ->orderBy('title', 'ASC')
                ->get()
                ->getResultArray();
            $teams = array_map(static function (array $row): array {
                $members = array_values(array_filter(array_map('intval', explode(',', (string) ($row['members'] ?? ''))), static fn (int $id): bool => $id > 0));
                return ['id' => (int) $row['id'], 'name' => (string) $row['title'], 'member_count' => count($members)];
            }, $teams);
        } catch (Throwable $exception) {
            // Team is a host-domain capability. An installation without that
            // optional table still has valid staff assignment semantics.
            $teams = [];
        }

        return ['staff' => $staff, 'teams' => $teams];
    }

    public function teamExists(int $id): bool
    {
        if ($id < 1) return false;
        try {
            return $this->db->table('team')->where('id', $id)->where('deleted', 0)->countAllResults() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function staffExists(int $id): bool
    {
        if ($id < 1) return false;
        try {
            return $this->db->table('users')
                ->where('id', $id)
                ->where('user_type', 'staff')
                ->where('status', 'active')
                ->where('deleted', 0)
                ->countAllResults() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }
}
