<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use Chatwoot_plugin\Libraries\Credential_cipher;
use CodeIgniter\Database\BaseBuilder;
use InvalidArgumentException;
use RuntimeException;

require_once dirname(__DIR__) . '/Libraries/Credential_cipher.php';

class Chat_instances_model extends Crud_model
{
    protected $table = null;

    private Credential_cipher $credentialCipher;

    private const WRITABLE_FIELDS = [
        'name',
        'evolution_instance_name',
        'internal_identifier',
        'base_url',
        'phone_number',
        'connection_status',
        'active',
        'last_sync_at',
    ];

    public function __construct(?Credential_cipher $credentialCipher = null)
    {
        $this->table = 'chat_instances';
        parent::__construct($this->table);
        $this->credentialCipher = $credentialCipher ?? new Credential_cipher();
    }

    public function get_by_id(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)
            ->select($this->publicSelect(), false)
            ->where('id', $id)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ? $this->normalizePublicRow($row) : null;
    }

    public function get_by_identifier(string $internalIdentifier): ?array
    {
        $internalIdentifier = trim($internalIdentifier);
        if ($internalIdentifier === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->select($this->publicSelect(), false)
            ->where('internal_identifier', $internalIdentifier)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ? $this->normalizePublicRow($row) : null;
    }

    public function get_by_evolution_name(string $evolutionInstanceName): ?array
    {
        $evolutionInstanceName = trim($evolutionInstanceName);
        if ($evolutionInstanceName === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->select($this->publicSelect(), false)
            ->where('evolution_instance_name', $evolutionInstanceName)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ? $this->normalizePublicRow($row) : null;
    }

    public function paginate_instances(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);

        $countBuilder = $this->applyFilters($this->db->table($this->table), $filters);
        $total = $countBuilder->countAllResults();

        $dataBuilder = $this->applyFilters($this->db->table($this->table), $filters);
        $rows = $dataBuilder
            ->select($this->publicSelect(), false)
            ->orderBy('active', 'DESC')
            ->orderBy('name', 'ASC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        $rows = array_map(fn (array $row): array => $this->normalizePublicRow($row), $rows);

        return $this->pageResult($rows, $total, $page, $perPage);
    }

    /**
     * Upserts by the stable internal identifier. Passing an empty api_key keeps
     * the existing encrypted value; use clear_api_key() for explicit removal.
     */
    public function upsert_instance(string $internalIdentifier, array $data): int
    {
        $internalIdentifier = trim($internalIdentifier);
        if ($internalIdentifier === '') {
            throw new InvalidArgumentException('Instance internal identifier cannot be empty.');
        }

        $existing = $this->db->table($this->table)
            ->select('id')
            ->where('internal_identifier', $internalIdentifier)
            ->get(1)
            ->getRowArray();

        $payload = $this->onlyWritable($data);
        $payload['internal_identifier'] = $internalIdentifier;
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;

        if (!empty($data['clear_api_key'])) {
            $payload['api_key_encrypted'] = null;
        } elseif (array_key_exists('api_key', $data) && is_string($data['api_key']) && trim($data['api_key']) !== '') {
            $payload['api_key_encrypted'] = $this->credentialCipher->encrypt($data['api_key']);
        }

        $payload = $this->normalizeInstancePayload($payload);

        if (!$existing) {
            foreach (['name', 'evolution_instance_name'] as $requiredField) {
                if (!isset($payload[$requiredField]) || trim((string) $payload[$requiredField]) === '') {
                    throw new InvalidArgumentException("Missing required instance field: {$requiredField}.");
                }
            }
        }

        if ($existing) {
            $success = $this->db->table($this->table)
                ->where('id', (int) $existing['id'])
                ->update($payload);
        } else {
            $success = $this->db->table($this->table)->insert($payload);
        }

        if ($success === false) {
            throw new RuntimeException('Unable to persist the Evolution instance.');
        }

        $row = $this->db->table($this->table)
            ->select('id')
            ->where('internal_identifier', $internalIdentifier)
            ->get(1)
            ->getRowArray();

        if (!$row) {
            throw new RuntimeException('Persisted Evolution instance could not be found.');
        }

        return (int) $row['id'];
    }

    /**
     * Updates an instance without allowing callers to change database-owned
     * fields. An omitted or blank api_key preserves the encrypted credential.
     */
    public function update_instance(int $id, array $data): bool
    {
        if ($id < 1 || !$this->get_by_id($id)) {
            throw new InvalidArgumentException('A valid active instance id is required.');
        }

        $payload = $this->normalizeInstancePayload($this->onlyWritable($data));

        foreach (['name', 'evolution_instance_name', 'internal_identifier'] as $requiredField) {
            if (array_key_exists($requiredField, $payload) && trim((string) $payload[$requiredField]) === '') {
                throw new InvalidArgumentException("Instance field cannot be empty: {$requiredField}.");
            }
        }

        if (!empty($data['clear_api_key'])) {
            $payload['api_key_encrypted'] = null;
        } elseif (array_key_exists('api_key', $data) && is_string($data['api_key']) && trim($data['api_key']) !== '') {
            $payload['api_key_encrypted'] = $this->credentialCipher->encrypt($data['api_key']);
        }

        if ($payload === []) {
            return true;
        }

        $payload['updated_at'] = gmdate('Y-m-d H:i:s');

        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update($payload);
    }

    public function get_decrypted_api_key(int $id): ?string
    {
        $row = $this->db->table($this->table)
            ->select('api_key_encrypted')
            ->where('id', $id)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        $storedValue = $row['api_key_encrypted'] ?? null;
        if (!is_string($storedValue) || $storedValue === '') {
            return null;
        }

        return $this->credentialCipher->decrypt($storedValue);
    }

    public function clear_api_key(int $id): bool
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A valid instance id is required.');
        }

        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update([
                'api_key_encrypted' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function soft_delete_instance(int $id): bool
    {
        return $id > 0 && $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->update(['active' => 0, 'deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function update_connection_status(int $id, string $status, ?string $lastSyncAt = null): bool
    {
        $status = trim($status);
        if ($id < 1 || $status === '') {
            throw new InvalidArgumentException('A valid instance and connection status are required.');
        }

        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update([
                'connection_status' => $status,
                'last_sync_at' => $lastSyncAt ?? gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function count_by_status(?bool $active = true): array
    {
        $builder = $this->db->table($this->table)
            ->select('connection_status, COUNT(id) AS total', false)
            ->where('deleted', 0);

        if ($active !== null) {
            $builder->where('active', $active ? 1 : 0);
        }

        $rows = $builder->groupBy('connection_status')->get()->getResultArray();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['connection_status']] = (int) $row['total'];
        }

        return $counts;
    }

    private function publicSelect(): string
    {
        return "id, name, evolution_instance_name, internal_identifier, base_url, phone_number, connection_status, active, last_sync_at, created_at, updated_at, CASE WHEN api_key_encrypted IS NOT NULL AND api_key_encrypted <> '' THEN 1 ELSE 0 END AS has_api_key";
    }

    private function normalizePublicRow(array $row): array
    {
        $row['has_api_key'] = (bool) ($row['has_api_key'] ?? false);

        return $row;
    }

    private function normalizeInstancePayload(array $payload): array
    {
        if (array_key_exists('base_url', $payload)) {
            $baseUrl = is_string($payload['base_url']) ? trim($payload['base_url']) : $payload['base_url'];
            $payload['base_url'] = $baseUrl === '' ? null : $baseUrl;
        }

        return $payload;
    }

    private function applyFilters(BaseBuilder $builder, array $filters): BaseBuilder
    {
        $builder->where('deleted', 0);

        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $builder->where('active', $filters['active'] ? 1 : 0);
        }

        $status = trim((string) ($filters['connection_status'] ?? ''));
        if ($status !== '') {
            $builder->where('connection_status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('evolution_instance_name', $search)
                ->orLike('internal_identifier', $search)
                ->orLike('phone_number', $search)
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
