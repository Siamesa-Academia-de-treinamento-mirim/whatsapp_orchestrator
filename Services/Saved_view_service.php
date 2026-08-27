<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_saved_views_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

class Saved_view_service
{
    public const SCHEMA_VERSION = 1;
    private const MAX_VIEWS = 50;

    public function __construct(
        private ?Chat_saved_views_model $views = null,
        ?BaseConnection $db = null,
        private ?Conversation_filter_service $filters = null
    )
    {
        $this->views ??= new Chat_saved_views_model();
        $this->filters ??= new Conversation_filter_service();
    }

    public function listForUser(int $userId): array
    {
        $result = $this->views->paginate_records(['user_id' => $userId], 1, self::MAX_VIEWS);
        return array_map([$this, 'map'], $result['data']);
    }

    public function create(array $input, int $userId): array
    {
        if (count($this->listForUser($userId)) >= self::MAX_VIEWS) throw new InvalidArgumentException('Limite de visualizacoes salvas atingido.');
        $data = $this->validated($input, $userId);
        $id = $this->views->create_record(['user_id' => $userId, 'name' => $data['name'], 'schema_version' => 1, 'filters_json' => json_encode($data['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $this->map($this->owned($id, $userId));
    }

    public function update(int $id, array $input, int $userId): array
    {
        $this->owned($id, $userId);
        $data = $this->validated($input, $userId);
        $this->views->update_record($id, ['name' => $data['name'], 'schema_version' => 1, 'filters_json' => json_encode($data['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $this->map($this->owned($id, $userId));
    }

    public function delete(int $id, int $userId): array
    {
        $this->owned($id, $userId);
        $this->views->soft_delete($id);
        return ['id' => $id, 'deleted' => true];
    }

    /** @return array{name:string,filters:array<string,mixed>} */
    private function validated(array $input, int $userId): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Nome de visualizacao invalido.');
        $version = (int) ($input['schema_version'] ?? self::SCHEMA_VERSION);
        if ($version !== self::SCHEMA_VERSION) throw new InvalidArgumentException('Schema de visualizacao invalido.');
        $filters = $input['filters'] ?? [];
        if (!is_array($filters)) throw new InvalidArgumentException('Filtros da visualizacao invalidos.');
        return ['name' => $name, 'filters' => $this->filters->normalizeSaved($filters, $userId)];
    }

    private function owned(int $id, int $userId): array
    {
        $row = $this->views->get_by_id($id);
        if (!$row || (int) ($row['user_id'] ?? 0) !== $userId) throw new RuntimeException('Visualizacao nao encontrada.', 404);
        return $row;
    }

    private function map(array $row): array
    {
        $filters = json_decode((string) ($row['filters_json'] ?? '{}'), true);
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'schema_version' => (int) $row['schema_version'], 'filters' => is_array($filters) ? $filters : [], 'sort_order' => (int) ($row['sort_order'] ?? 0), 'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null];
    }
}
