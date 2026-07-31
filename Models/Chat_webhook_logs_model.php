<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use CodeIgniter\Database\BaseBuilder;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class Chat_webhook_logs_model extends Crud_model
{
    protected $table = null;

    private const WRITABLE_FIELDS = [
        'instance_id',
        'event_name',
        'event_dedupe_key',
        'payload',
        'response_payload',
        'error_message',
        'http_status',
        'success',
        'processed_at',
    ];

    public function __construct()
    {
        $this->table = 'chat_webhook_logs';
        parent::__construct($this->table);
    }

    public function record_event(array $data): int
    {
        $payload = $this->onlyWritable($data);
        $eventName = trim((string) ($payload['event_name'] ?? ''));
        if ($eventName === '') {
            throw new InvalidArgumentException('Webhook event name cannot be empty.');
        }

        $payload['event_name'] = $eventName;
        $payload['instance_id'] = isset($payload['instance_id']) && (int) $payload['instance_id'] > 0
            ? (int) $payload['instance_id']
            : null;

        if (isset($payload['event_dedupe_key']) && trim((string) $payload['event_dedupe_key']) === '') {
            $payload['event_dedupe_key'] = null;
        }

        foreach (['payload', 'response_payload'] as $jsonField) {
            if (isset($payload[$jsonField]) && is_array($payload[$jsonField])) {
                $payload[$jsonField] = $this->encodeJson($payload[$jsonField]);
            }
        }

        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;

        if (!empty($payload['event_dedupe_key'])) {
            $success = $this->db->table($this->table)->upsert($payload);
            if ($success === false) {
                throw new RuntimeException('Unable to persist the webhook event.');
            }

            $row = $this->db->table($this->table)
                ->select('id')
                ->where('event_dedupe_key', $payload['event_dedupe_key'])
                ->get(1)
                ->getRowArray();

            if (!$row) {
                throw new RuntimeException('Persisted webhook event could not be found.');
            }

            return (int) $row['id'];
        }

        $payload['created_at'] = gmdate('Y-m-d H:i:s');
        if (!$this->db->table($this->table)->insert($payload)) {
            throw new RuntimeException('Unable to persist the webhook log.');
        }

        return (int) $this->db->insertID();
    }

    public function was_processed(string $eventDedupeKey): bool
    {
        $eventDedupeKey = trim($eventDedupeKey);
        if ($eventDedupeKey === '') {
            return false;
        }

        return $this->db->table($this->table)
            ->where('event_dedupe_key', $eventDedupeKey)
            ->where('success', 1)
            ->where('deleted', 0)
            ->countAllResults() > 0;
    }

    public function paginate_logs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);

        $total = $this->applyFilters($this->db->table($this->table), $filters)
            ->countAllResults();

        $rows = $this->applyFilters($this->db->table($this->table), $filters)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

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

    public function count_by_result(): array
    {
        $rows = $this->db->table($this->table)
            ->select('success, COUNT(id) AS total', false)
            ->where('deleted', 0)
            ->groupBy('success')
            ->get()
            ->getResultArray();

        $counts = ['success' => 0, 'failure' => 0];
        foreach ($rows as $row) {
            $counts[(int) $row['success'] === 1 ? 'success' : 'failure'] = (int) $row['total'];
        }

        return $counts;
    }

    public static function build_event_dedupe_key(
        ?int $instanceId,
        string $eventName,
        string $externalEventId
    ): string {
        return hash('sha256', implode('|', [
            (string) ($instanceId ?? 0),
            trim($eventName),
            trim($externalEventId),
        ]));
    }

    private function applyFilters(BaseBuilder $builder, array $filters): BaseBuilder
    {
        $builder->where('deleted', 0);

        $instanceId = (int) ($filters['instance_id'] ?? 0);
        if ($instanceId > 0) {
            $builder->where('instance_id', $instanceId);
        }

        $eventName = trim((string) ($filters['event_name'] ?? ''));
        if ($eventName !== '') {
            $builder->where('event_name', $eventName);
        }

        if (array_key_exists('success', $filters) && $filters['success'] !== null) {
            $builder->where('success', $filters['success'] ? 1 : 0);
        }

        if (isset($filters['http_status']) && (int) $filters['http_status'] > 0) {
            $builder->where('http_status', (int) $filters['http_status']);
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

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Webhook payload could not be encoded as JSON.', 0, $exception);
        }
    }

    private function pagination(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
