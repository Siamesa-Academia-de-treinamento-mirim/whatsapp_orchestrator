<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use CodeIgniter\Database\BaseBuilder;
use InvalidArgumentException;
use RuntimeException;

class Chat_conversations_model extends Crud_model
{
    protected $table = null;

    private const WRITABLE_FIELDS = [
        'phone_number',
        'contact_name',
        'profile_picture_url',
        'last_message_preview',
        'last_message_at',
        'unread_count',
        'archived',
        'status',
        'contact_id',
        'priority',
        'assignee_id',
        'team_id',
        'resolved_at',
        'resolved_by',
        'ai_status',
        'ai_summary',
        'last_human_message_at',
        'last_bot_message_at',
        'first_response_at',
        'first_response_seconds',
    ];

    public function __construct()
    {
        $this->table = 'chat_conversations';
        parent::__construct($this->table);
    }

    public function get_by_id(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function get_by_remote_jid(int $instanceId, string $remoteJid): ?array
    {
        $remoteJid = trim($remoteJid);
        if ($instanceId < 1 || $remoteJid === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('remote_jid', $remoteJid)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function paginate_conversations(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);

        $total = $this->applyFilters($this->db->table($this->table), $filters)
            ->countAllResults();

        $rows = $this->applyFilters($this->db->table($this->table), $filters)
            ->orderBy('last_message_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return $this->pageResult($rows, $total, $page, $perPage);
    }

    public function upsert_conversation(int $instanceId, string $remoteJid, array $data = []): int
    {
        $remoteJid = trim($remoteJid);
        if ($instanceId < 1 || $remoteJid === '') {
            throw new InvalidArgumentException('A valid instance and remote JID are required.');
        }

        $payload = $this->onlyWritable($data);
        $payload['instance_id'] = $instanceId;
        $payload['remote_jid'] = $remoteJid;
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;

        $success = $this->db->table($this->table)->upsert($payload);
        if ($success === false) {
            throw new RuntimeException('Unable to persist the conversation.');
        }

        $row = $this->db->table($this->table)
            ->select('id')
            ->where('instance_id', $instanceId)
            ->where('remote_jid', $remoteJid)
            ->get(1)
            ->getRowArray();

        if (!$row) {
            throw new RuntimeException('Persisted conversation could not be found.');
        }

        return (int) $row['id'];
    }

    public function get_instance_counters(array $instanceIds = []): array
    {
        $builder = $this->db->table($this->table)
            ->select('instance_id')
            ->select('COUNT(id) AS conversation_count', false)
            ->select('COALESCE(SUM(unread_count), 0) AS unread_count', false)
            ->select("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count", false)
            ->where('deleted', 0)
            ->where('archived', 0);

        $instanceIds = array_values(array_unique(array_filter(array_map('intval', $instanceIds), static fn (int $id): bool => $id > 0)));
        if ($instanceIds) {
            $builder->whereIn('instance_id', $instanceIds);
        }

        $rows = $builder->groupBy('instance_id')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['instance_id'] = (int) $row['instance_id'];
            $row['conversation_count'] = (int) $row['conversation_count'];
            $row['unread_count'] = (int) $row['unread_count'];
            $row['open_count'] = (int) $row['open_count'];
        }
        unset($row);

        return $rows;
    }

    public function increment_unread(int $id, int $amount = 1): bool
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Conversation id must be positive.');
        }

        $amount = max(1, $amount);

        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->set('unread_count', "unread_count + {$amount}", false)
            ->set('updated_at', gmdate('Y-m-d H:i:s'))
            ->update();
    }

    public function mark_read(int $id): bool
    {
        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update([
                'unread_count' => 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function count_matching(array $filters = []): int
    {
        return $this->applyFilters($this->db->table($this->table), $filters)
            ->countAllResults();
    }

    private function applyFilters(BaseBuilder $builder, array $filters): BaseBuilder
    {
        $builder->where('deleted', 0);

        $instanceId = (int) ($filters['instance_id'] ?? 0);
        if ($instanceId > 0) {
            $builder->where('instance_id', $instanceId);
        }

        if (!empty($filters['instance_ids']) && is_array($filters['instance_ids'])) {
            $instanceIds = array_values(array_unique(array_filter(
                array_map('intval', $filters['instance_ids']),
                static fn (int $id): bool => $id > 0
            )));
            if ($instanceIds) {
                $builder->whereIn('instance_id', $instanceIds);
            }
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $builder->where('status', $status);
        }

        if (array_key_exists('archived', $filters) && $filters['archived'] !== null) {
            $builder->where('archived', $filters['archived'] ? 1 : 0);
        }

        if (!empty($filters['unassigned'])) {
            $builder->where('assignee_id IS NULL', null, false);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $builder->groupStart()
                ->like('contact_name', $search)
                ->orLike('phone_number', $search)
                ->orLike('remote_jid', $search)
                ->orLike('last_message_preview', $search)
                ->groupEnd();
        }

        return $builder;
    }

    private function onlyWritable(array $data): array
    {
        $payload = [];
        foreach (self::WRITABLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        return $payload;
    }

    private function pagination(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function pageResult(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }
}
