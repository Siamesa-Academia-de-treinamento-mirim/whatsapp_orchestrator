<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_tags_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_tags';
    protected array $writableFields = ['name', 'normalized_name', 'color'];

    public function resolve(string $name): int
    {
        $normalized = mb_strtolower(trim($name));
        $row = $this->db->table($this->logicalTable)->where('normalized_name', $normalized)->where('deleted', 0)->get(1)->getRowArray();
        return $row ? (int) $row['id'] : $this->create_record(['name' => trim($name), 'normalized_name' => $normalized]);
    }

    public function names_for(string $linkTable, string $ownerColumn, int $ownerId): array
    {
        $linkTable = $this->db->prefixTable($linkTable);
        $tagsTable = $this->db->prefixTable($this->logicalTable);
        $rows = $this->db->table($linkTable)
            ->select($tagsTable . '.name')
            ->join($tagsTable, $tagsTable . '.id = ' . $linkTable . '.tag_id AND ' . $tagsTable . '.deleted = 0')
            ->where($linkTable . '.' . $ownerColumn, $ownerId)
            ->where($linkTable . '.deleted', 0)
            ->orderBy($tagsTable . '.name', 'ASC')
            ->get()->getResultArray();
        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }
}
