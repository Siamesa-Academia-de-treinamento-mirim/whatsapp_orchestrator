<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use InvalidArgumentException;
use RuntimeException;

abstract class Chat_domain_model extends Crud_model
{
    protected string $logicalTable;
    protected array $writableFields = [];
    protected array $filterableFields = [];

    public function __construct()
    {
        parent::__construct($this->logicalTable);
    }

    public function get_by_id(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->table($this->logicalTable)
            ->where('id', $id)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function create_record(array $data): int
    {
        $payload = $this->onlyWritable($data);
        $now = gmdate('Y-m-d H:i:s');
        $payload['created_at'] = $payload['created_at'] ?? $now;
        $payload['updated_at'] = $now;
        $payload['deleted'] = 0;
        if (!$this->db->table($this->logicalTable)->insert($payload)) {
            throw new RuntimeException('Unable to create record in ' . $this->logicalTable . '.');
        }

        return (int) $this->db->insertID();
    }

    public function update_record(int $id, array $data): bool
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Record id must be positive.');
        }
        $payload = $this->onlyWritable($data);
        if ($payload === []) {
            return true;
        }
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');

        return $this->db->table($this->logicalTable)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update($payload);
    }

    public function soft_delete(int $id): bool
    {
        return $id > 0 && $this->db->table($this->logicalTable)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update(['deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function paginate_records(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $builder = $this->db->table($this->logicalTable)->where('deleted', 0);
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterableFields, true) && $value !== null && $value !== '') {
                $builder->where($field, $value);
            }
        }
        $totalBuilder = clone $builder;
        $total = $totalBuilder->countAllResults();
        $rows = $builder->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ];
    }

    protected function onlyWritable(array $data): array
    {
        return array_intersect_key($data, array_flip($this->writableFields));
    }
}
