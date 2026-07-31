<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_quick_replies_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use InvalidArgumentException;
use RuntimeException;

class Quick_reply_service
{
    public function __construct(
        private ?Chat_quick_replies_model $replies = null,
        private ?Audit_service $audit = null,
        private ?Chat_settings_model $settings = null
    ) {
        $this->replies ??= new Chat_quick_replies_model();
        $this->audit ??= new Audit_service();
        $this->settings ??= new Chat_settings_model();
    }

    public function list(bool $activeOnly = true): array
    {
        $filters = $activeOnly ? ['active' => 1] : [];
        $result = $this->replies->paginate_records($filters, 1, 200);
        $rows = array_map([$this, 'map'], $result['data']);
        if ($rows === []) {
            $configured = json_decode((string) $this->settings->get_value('quick_replies_json', '[]'), true);
            if (is_array($configured)) {
                foreach ($configured as $index => $item) {
                    if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') continue;
                    $rows[] = ['id' => 'setting-' . $index, 'title' => (string) ($item['title'] ?? 'Resposta'), 'text' => (string) $item['text'], 'shortcut' => (string) ($item['shortcut'] ?? ''), 'active' => true, 'sort_order' => $index];
                }
            }
        }
        return $rows;
    }

    public function save(array $input, int $actorId, ?int $id = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $text = trim((string) ($input['text'] ?? $input['text_content'] ?? ''));
        $shortcut = trim((string) ($input['shortcut'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Informe um titulo de ate 150 caracteres.');
        }
        if ($text === '' || mb_strlen($text) > 10000) {
            throw new InvalidArgumentException('Informe uma resposta de ate 10000 caracteres.');
        }
        if ($shortcut !== '' && (!preg_match('/^\/[A-Za-z0-9_-]{1,70}$/', $shortcut))) {
            throw new InvalidArgumentException('O atalho deve iniciar com / e usar letras, numeros, hifen ou sublinhado.');
        }
        $before = $id ? $this->replies->get_by_id($id) : null;
        if ($id && !$before) {
            throw new RuntimeException('Resposta rapida nao encontrada.', 404);
        }
        $payload = [
            'title' => $title,
            'text_content' => $text,
            'shortcut' => $shortcut ?: null,
            'scope_type' => 'global',
            'scope_id' => null,
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'created_by' => $actorId,
        ];
        if ($id) {
            $this->replies->update_record($id, $payload);
        } else {
            $id = $this->replies->create_record($payload);
        }
        $saved = $this->replies->get_by_id($id) ?: [];
        $this->audit->record($actorId, $before ? 'quick_reply.updated' : 'quick_reply.created', 'quick_reply', $id, null, $before ?: [], $saved);
        return $this->map($saved);
    }

    public function delete(int $id, int $actorId): void
    {
        $before = $this->replies->get_by_id($id);
        if (!$before) {
            throw new RuntimeException('Resposta rapida nao encontrada.', 404);
        }
        $this->replies->soft_delete($id);
        $this->audit->record($actorId, 'quick_reply.deleted', 'quick_reply', $id, null, $before);
    }

    private function map(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'text' => (string) ($row['text_content'] ?? ''),
            'shortcut' => (string) ($row['shortcut'] ?? ''),
            'active' => !empty($row['active']),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
}
