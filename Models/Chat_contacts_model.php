<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_contacts_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_contacts';
    protected array $writableFields = ['instance_id', 'name', 'phone_normalized', 'email', 'company', 'city', 'source', 'notes', 'profile_picture_url', 'opt_out', 'manually_edited', 'scope_key', 'last_activity_at'];
    protected array $filterableFields = ['instance_id', 'opt_out', 'source'];

    public function find_by_scope(string $scopeKey): ?array
    {
        $row = $this->db->table($this->logicalTable)->where('scope_key', $scopeKey)->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }

    public function find_by_phone(string $phone, ?int $instanceId = null): ?array
    {
        $builder = $this->db->table($this->logicalTable)->where('phone_normalized', $phone)->where('deleted', 0);
        if ($instanceId !== null) {
            $builder->groupStart()->where('instance_id', $instanceId)->orWhere('instance_id IS NULL', null, false)->groupEnd();
        }
        $row = $builder->orderBy('instance_id', 'DESC')->get(1)->getRowArray();
        return $row ?: null;
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $contactsTable = $this->db->prefixTable($this->logicalTable);
        $contactTagsTable = $this->db->prefixTable('chat_contact_tags');
        $tagsTable = $this->db->prefixTable('chat_tags');
        $builder = $this->db->table($contactsTable)->where($contactsTable . '.deleted', 0);
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $filters['ids']), static fn (int $id): bool => $id > 0)));
            if ($ids) $builder->whereIn($contactsTable . '.id', $ids);
        }
        if (!empty($filters['instance_id'])) $builder->where($contactsTable . '.instance_id', (int) $filters['instance_id']);
        if (array_key_exists('opt_out', $filters) && $filters['opt_out'] !== null) $builder->where($contactsTable . '.opt_out', $filters['opt_out'] ? 1 : 0);
        if (array_key_exists('identified', $filters) && $filters['identified'] !== null) {
            if ($filters['identified']) {
                $builder->where($contactsTable . '.name IS NOT NULL', null, false)
                    ->where('TRIM(' . $contactsTable . ".name) != ''", null, false)
                    ->where($contactsTable . '.name != ' . $contactsTable . '.phone_normalized', null, false)
                    ->where('LOWER(' . $contactsTable . '.name) !=', 'contato');
            } else {
                $builder->groupStart()
                    ->where($contactsTable . '.name IS NULL', null, false)
                    ->orWhere('TRIM(' . $contactsTable . ".name) = ''", null, false)
                    ->orWhere($contactsTable . '.name = ' . $contactsTable . '.phone_normalized', null, false)
                    ->orWhere('LOWER(' . $contactsTable . '.name)', 'contato')
                    ->groupEnd();
            }
        }
        if (!empty($filters['q'])) {
            $q = (string) $filters['q'];
            $builder->groupStart()->like($contactsTable . '.name', $q)->orLike($contactsTable . '.phone_normalized', preg_replace('/\D+/', '', $q) ?: $q)->orLike($contactsTable . '.email', $q)->orLike($contactsTable . '.company', $q)->groupEnd();
        }
        if (!empty($filters['tag'])) {
            $builder->join($contactTagsTable, $contactTagsTable . '.contact_id = ' . $contactsTable . '.id AND ' . $contactTagsTable . '.deleted = 0')
                ->join($tagsTable, $tagsTable . '.id = ' . $contactTagsTable . '.tag_id AND ' . $tagsTable . '.deleted = 0')
                ->where($tagsTable . '.normalized_name', mb_strtolower(trim((string) $filters['tag'])));
        }
        $countBuilder = clone $builder;
        $total = $countBuilder->select($contactsTable . '.id')->distinct()->countAllResults();
        $sort = ($filters['sort'] ?? '') === 'name' ? $contactsTable . '.name' : $contactsTable . '.last_activity_at';
        $rows = $builder->select($contactsTable . '.*')->distinct()->orderBy($sort, $sort === $contactsTable . '.name' ? 'ASC' : 'DESC')->orderBy($contactsTable . '.id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return ['data' => $rows, 'meta' => ['page' => $page, 'limit' => $perPage, 'total' => $total, 'has_more' => $page * $perPage < $total]];
    }
}
