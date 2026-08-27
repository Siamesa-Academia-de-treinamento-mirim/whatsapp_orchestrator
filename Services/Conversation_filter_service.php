<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_instances_model;
use InvalidArgumentException;

/** Shared canonical conversation-filter validation for list queries and saved views. */
class Conversation_filter_service
{
    public const SAVED_KEYS = [
        'status', 'instance', 'channel', 'assignee', 'team', 'tags', 'priority',
        'unread', 'conversation_type', 'bot_status', 'last_activity_from',
        'last_activity_to', 'search',
    ];

    public function __construct(
        private ?Chat_instances_model $instances = null,
        private ?Conversation_workflow_service $workflow = null
    ) {
        $this->instances ??= new Chat_instances_model();
        $this->workflow ??= new Conversation_workflow_service();
    }

    /** Convert HTTP query aliases into the canonical model filter contract. */
    public function fromQuery(array $input, int $actorId): array
    {
        $filters = [
            'status' => $input['status'] ?? '',
            'instance' => $input['instance'] ?? $input['instance_id'] ?? '',
            'channel' => $input['channel'] ?? '',
            'assignee' => $input['assignee'] ?? $input['assignee_id'] ?? '',
            'team' => $input['team'] ?? $input['team_id'] ?? '',
            'tags' => $input['tags'] ?? $input['tag'] ?? '',
            'priority' => $input['priority'] ?? '',
            'unread' => $input['unread'] ?? '',
            'conversation_type' => $input['conversation_type'] ?? '',
            'bot_status' => $input['bot_status'] ?? '',
            'last_activity_from' => $input['last_activity_from'] ?? '',
            'last_activity_to' => $input['last_activity_to'] ?? '',
            'search' => $input['search'] ?? '',
        ];

        return $this->toQuery($this->normalizeSaved($filters, $actorId), $actorId);
    }

    /** Validate and normalize exactly the payload persisted by a saved view. */
    public function normalizeSaved(array $filters, int $actorId): array
    {
        $unknown = array_diff(array_keys($filters), self::SAVED_KEYS);
        if ($unknown) throw new InvalidArgumentException('Filtro nao permitido na visualizacao.');

        $out = [];
        $rawStatus = strtolower($this->scalar($filters['status'] ?? '', 'Status invalido na visualizacao.'));
        $status = $rawStatus;
        if ($status === 'all' || $status === 'unassigned') $status = '';
        if ($status !== '' && !in_array($status, Conversation_workflow_service::STATUSES, true)) {
            throw new InvalidArgumentException('Status invalido na visualizacao.');
        }
        if ($status !== '') $out['status'] = $status;

        $instance = $this->positiveAlias($filters, 'instance', 'Canal invalido na visualizacao.');
        $channel = $this->positiveAlias($filters, 'channel', 'Canal invalido na visualizacao.');
        if ($instance && $channel && $instance !== $channel) {
            throw new InvalidArgumentException('Informe apenas um canal na visualizacao.');
        }
        $channelId = $channel ?: $instance;
        if ($channelId) {
            $row = $this->instances->get_by_id($channelId);
            if (!$row || empty($row['active'])) throw new InvalidArgumentException('Filtro de instancia invalido.');
            $out[$channel ? 'channel' : 'instance'] = (string) $channelId;
        }

        $team = $this->positiveAlias($filters, 'team', 'Equipe invalida na visualizacao.');
        if ($team && !$this->workflow->teamExists($team)) throw new InvalidArgumentException('Filtro de equipe invalido.');
        if ($team) $out['team'] = (string) $team;

        $assignee = $this->scalar($filters['assignee'] ?? '', 'Atendente invalido na visualizacao.');
        $assignee = strtolower($assignee);
        if ($rawStatus === 'unassigned') {
            if ($assignee !== '' && $assignee !== 'unassigned') throw new InvalidArgumentException('Filtro de atendente invalido na visualizacao.');
            $out['assignee'] = 'unassigned';
        } elseif ($assignee === 'me') {
            if ($actorId < 1) throw new InvalidArgumentException('Atendente invalido na visualizacao.');
            $out['assignee'] = 'me';
        } elseif ($assignee === 'unassigned') {
            $out['assignee'] = 'unassigned';
        } elseif ($assignee !== '') {
            if (!ctype_digit($assignee) || (int) $assignee < 1 || !$this->workflow->staffExists((int) $assignee)) {
                throw new InvalidArgumentException('Atendente invalido na visualizacao.');
            }
            $out['assignee'] = (string) ((int) $assignee);
        }

        $priority = strtolower($this->scalar($filters['priority'] ?? '', 'Prioridade invalida na visualizacao.'));
        if ($priority === 'normal') $priority = 'medium';
        if ($priority !== '' && !in_array($priority, Conversation_workflow_service::PRIORITIES, true)) {
            throw new InvalidArgumentException('Prioridade invalida na visualizacao.');
        }
        if ($priority !== '') $out['priority'] = $priority;

        $unread = $this->scalar($filters['unread'] ?? '', 'Filtro de leitura invalido.');
        if ($unread !== '' && !in_array($unread, ['0', '1'], true)) throw new InvalidArgumentException('Filtro de leitura invalido.');
        if ($unread !== '') $out['unread'] = $unread;

        $type = strtolower($this->scalar($filters['conversation_type'] ?? '', 'Tipo de conversa invalido.'));
        if ($type !== '' && !in_array($type, ['individual', 'group'], true)) throw new InvalidArgumentException('Tipo de conversa invalido.');
        if ($type !== '') $out['conversation_type'] = $type;

        $bot = strtolower($this->scalar($filters['bot_status'] ?? '', 'Estado do bot invalido.'));
        if ($bot === 'all') $bot = '';
        if ($bot !== '' && !in_array($bot, ['active', 'running', 'paused', 'handoff'], true)) throw new InvalidArgumentException('Estado do bot invalido.');
        if ($bot !== '') $out['bot_status'] = $bot;

        foreach (['last_activity_from', 'last_activity_to'] as $field) {
            $date = $this->date($filters[$field] ?? '', 'Data de atividade invalida.');
            if ($date !== '') $out[$field] = $date;
        }

        $tags = $this->tags($filters['tags'] ?? '');
        if ($tags !== []) $out['tags'] = implode(',', $tags);

        $search = $this->scalar($filters['search'] ?? '', 'Busca da visualizacao invalida.');
        if (mb_strlen($search) > 191) throw new InvalidArgumentException('Busca da visualizacao muito longa.');
        if ($search !== '') $out['search'] = $search;

        return $out;
    }

    /** @return array<string,mixed> */
    public function toQuery(array $saved, int $actorId): array
    {
        $query = ['archived' => false, 'search' => (string) ($saved['search'] ?? '')];
        if (!empty($saved['status'])) $query['status'] = $saved['status'];
        $channel = $saved['channel'] ?? $saved['instance'] ?? '';
        if ($channel !== '') $query['instance_id'] = (int) $channel;
        $assignee = (string) ($saved['assignee'] ?? '');
        if ($assignee === 'unassigned') $query['unassigned'] = true;
        elseif ($assignee === 'me') $query['assignee_id'] = $actorId;
        elseif ($assignee !== '') $query['assignee_id'] = (int) $assignee;
        if (!empty($saved['team'])) $query['team_id'] = (int) $saved['team'];
        foreach (['priority', 'conversation_type', 'bot_status'] as $field) {
            if (!empty($saved[$field])) $query[$field] = $saved[$field];
        }
        if (array_key_exists('unread', $saved)) $query['unread'] = $saved['unread'] === '1';
        foreach (['last_activity_from', 'last_activity_to'] as $field) {
            if (!empty($saved[$field])) {
                $date = new \DateTimeImmutable($saved[$field] . ($field === 'last_activity_to' ? ' 23:59:59' : ' 00:00:00'), new \DateTimeZone('UTC'));
                $query[$field] = $date->format('Y-m-d H:i:s');
            }
        }
        if (!empty($saved['tags'])) $query['tags'] = $this->tags($saved['tags']);
        return $query;
    }

    private function positiveAlias(array $filters, string $key, string $message): int
    {
        $value = $this->scalar($filters[$key] ?? '', $message);
        if ($value === '') return 0;
        if (!ctype_digit($value) || (int) $value < 1) throw new InvalidArgumentException($message);
        return (int) $value;
    }

    private function scalar($value, string $message): string
    {
        if (is_array($value) || is_object($value)) throw new InvalidArgumentException($message);
        return trim((string) $value);
    }

    private function date($value, string $message): string
    {
        $value = $this->scalar($value, $message);
        if ($value === '') return '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new InvalidArgumentException($message);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    /** @return array<int,string> */
    private function tags($value): array
    {
        if (is_array($value)) $raw = $value;
        else {
            $raw = $value === '' ? [] : preg_split('/\s*,\s*/', $this->scalar($value, 'Filtro de etiqueta invalido.'));
            $raw = is_array($raw) ? $raw : [];
        }
        if (count($raw) > 20) throw new InvalidArgumentException('Filtro de etiqueta invalido.');
        $tags = [];
        foreach ($raw as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') continue;
            if (mb_strlen($tag) > 100 || !preg_match('/^[\pL\pN _.-]+$/u', $tag)) throw new InvalidArgumentException('Filtro de etiqueta invalido.');
            $tags[mb_strtolower($tag)] = $tag;
        }
        return array_values($tags);
    }
}
