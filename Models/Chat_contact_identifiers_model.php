<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_contact_identifiers_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_contact_identifiers';
    protected array $writableFields = ['contact_id', 'instance_id', 'identifier_type', 'identifier_value', 'is_primary'];
    protected array $filterableFields = ['contact_id', 'instance_id', 'identifier_type'];

    public function find_identifier(?int $instanceId, string $type, string $value): ?array
    {
        $builder = $this->db->table($this->logicalTable)->where('identifier_type', $type)->where('identifier_value', $value)->where('deleted', 0);
        $instanceId === null ? $builder->where('instance_id IS NULL', null, false) : $builder->where('instance_id', $instanceId);
        $row = $builder->get(1)->getRowArray();
        return $row ?: null;
    }

    public function add_identifier(int $contactId, ?int $instanceId, string $type, string $value, bool $primary = false): int
    {
        $existing = $this->find_identifier($instanceId, $type, $value);
        if ($existing) return (int) $existing['id'];
        return $this->create_record(['contact_id' => $contactId, 'instance_id' => $instanceId, 'identifier_type' => $type, 'identifier_value' => $value, 'is_primary' => $primary ? 1 : 0]);
    }
}
