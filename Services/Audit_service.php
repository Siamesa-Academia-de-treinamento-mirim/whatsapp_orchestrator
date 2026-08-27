<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_audit_logs_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Throwable;

class Audit_service
{
    public function __construct(
        private ?Chat_audit_logs_model $logs = null,
        private ?Chat_settings_model $settings = null,
        private ?Payload_sanitizer $sanitizer = null
    ) {
        $this->logs ??= new Chat_audit_logs_model();
        $this->settings ??= new Chat_settings_model();
        $this->sanitizer ??= new Payload_sanitizer();
    }

    public function record(
        ?int $actorId,
        string $action,
        string $resourceType,
        $resourceId = null,
        ?int $instanceId = null,
        array $before = [],
        array $after = [],
        ?string $correlationId = null
    ): void {
        if ((int) $this->settings->get_value('audit_enabled', 1) !== 1) {
            return;
        }
        try {
            $request = service('request');
            $ipAddress = null;
            $userAgent = null;
            if ($request) {
                $ipAddress = substr((string) $request->getIPAddress(), 0, 64) ?: null;
                if (method_exists($request, 'getUserAgent')) {
                    $agent = $request->getUserAgent();
                    if (is_object($agent) && method_exists($agent, 'getAgentString')) {
                        $userAgent = substr((string) $agent->getAgentString(), 0, 500) ?: null;
                    } elseif (is_scalar($agent) || $agent instanceof \Stringable) {
                        $userAgent = substr((string) $agent, 0, 500) ?: null;
                    }
                }
            }
            $this->logs->create_record([
                'actor_user_id' => $actorId ?: null,
                'action' => substr($action, 0, 120),
                'resource_type' => substr($resourceType, 0, 64),
                'resource_id' => $resourceId === null ? null : substr((string) $resourceId, 0, 191),
                'instance_id' => $instanceId,
                'correlation_id' => $correlationId ? substr($correlationId, 0, 191) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'before_json' => $before === [] ? null : $this->sanitizer->sanitize_to_json($before),
                'after_json' => $after === [] ? null : $this->sanitizer->sanitize_to_json($after),
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin audit write failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        }
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list(array $filters, int $page = 1, int $limit = 50): array
    {
        $allowed = array_intersect_key($filters, array_flip(['actor_user_id', 'action', 'resource_type', 'resource_id', 'instance_id']));
        $result = $this->logs->paginate_records($allowed, $page, min(100, max(1, $limit)));
        $result['data'] = array_map(static function (array $row): array {
            foreach (['before_json' => 'before', 'after_json' => 'after'] as $source => $target) {
                $decoded = json_decode((string) ($row[$source] ?? ''), true);
                $row[$target] = is_array($decoded) ? $decoded : [];
                unset($row[$source]);
            }
            unset($row['deleted'], $row['updated_at']);
            return $row;
        }, $result['data']);
        return $result;
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function conversationActivity(int $conversationId, int $page = 1, int $limit = 30): array
    {
        $result = $this->list([
            'resource_type' => 'conversation',
            'resource_id' => (string) $conversationId,
        ], $page, min(100, max(1, $limit)));
        $allowed = [
            'conversation.opened' => 'Conversa aberta',
            'conversation.pending' => 'Conversa pendente',
            'conversation.resolved' => 'Conversa resolvida',
            'conversation.snoozed' => 'Conversa adiada',
            'conversation.read' => 'Conversa marcada como lida',
            'conversation.unread' => 'Conversa marcada como nao lida',
            'conversation.assigned' => 'Responsavel atualizado',
            'conversation.team_assigned' => 'Equipe atualizada',
            'conversation.priority_changed' => 'Prioridade atualizada',
            'conversation.tags_changed' => 'Etiquetas atualizadas',
            'conversation.bot_paused' => 'Bot pausado',
            'conversation.bot_resumed' => 'Bot retomado',
            'bot.conversation_paused' => 'Bot pausado',
            'bot.conversation_resumed' => 'Bot retomado',
        ];
        $actorIds = [];
        $assigneeIds = [];
        $teamIds = [];
        foreach ($result['data'] as $row) {
            $actorId = (int) ($row['actor_user_id'] ?? 0);
            if ($actorId > 0) $actorIds[$actorId] = true;
            foreach (['before', 'after'] as $side) {
                $details = is_array($row[$side] ?? null) ? $row[$side] : [];
                $assigneeId = (int) ($details['assignee_id'] ?? 0);
                $teamId = (int) ($details['team_id'] ?? 0);
                if ($assigneeId > 0) $assigneeIds[$assigneeId] = true;
                if ($teamId > 0) $teamIds[$teamId] = true;
            }
        }
        $db = db_connect('default');
        $actors = $this->resolveStaffNames($db, array_keys($actorIds));
        $assignees = $this->resolveStaffNames($db, array_keys($assigneeIds), true);
        $teams = $this->resolveTeamNames($db, array_keys($teamIds));
        $projected = [];
        foreach ($result['data'] as $row) {
            $action = (string) ($row['action'] ?? '');
            $before = is_array($row['before'] ?? null) ? $row['before'] : [];
            $after = is_array($row['after'] ?? null) ? $row['after'] : [];
            $details = $this->projectActivityDetails($before, $after, $assignees, $teams);
            $actorId = !empty($row['actor_user_id']) ? (int) $row['actor_user_id'] : null;
            $actor = $actorId ? ($actors[$actorId] ?? 'Usuario indisponivel') : 'Sistema';
            $projected[] = [
                'id' => (int) ($row['id'] ?? 0),
                'action' => $action,
                'label' => $allowed[$action] ?? 'Atualizacao da conversa',
                'actor_id' => $actorId,
                'actor' => $actor,
                'text' => $this->activityText($action, $actor, $before, $after, $details),
                'details' => $details,
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        $result['data'] = $projected;
        return $result;
    }

    private function resolveStaffNames($db, array $ids, bool $includeInactive = false): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if (!$ids) return [];
        $rows = $db->table('users')->select('id,first_name,last_name,status,deleted')->whereIn('id', $ids)->get()->getResultArray();
        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($includeInactive && ((int) ($row['deleted'] ?? 0) === 1 || (string) ($row['status'] ?? '') !== 'active')) $name .= ' (inativo)';
            $names[(int) $row['id']] = $name !== '' ? $name : 'Atendente #' . (int) $row['id'];
        }
        foreach ($ids as $id) {
            if (!isset($names[$id])) $names[$id] = 'Atendente #' . $id;
        }
        return $names;
    }

    private function resolveTeamNames($db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if (!$ids) return [];
        $names = [];
        try {
            $rows = $db->table('team')->select('id,title,deleted')->whereIn('id', $ids)->get()->getResultArray();
            foreach ($rows as $row) {
                $name = (string) ($row['title'] ?? '');
                if ((int) ($row['deleted'] ?? 0) === 1) $name .= ' (inativa)';
                $names[(int) $row['id']] = $name !== '' ? $name : 'Equipe #' . (int) $row['id'];
            }
        } catch (Throwable $exception) {
            // Team is optional in older host installations.
        }
        foreach ($ids as $id) {
            if (!isset($names[$id])) $names[$id] = 'Equipe #' . $id;
        }
        return $names;
    }

    private function projectActivityDetails(array $before, array $after, array $assignees, array $teams): array
    {
        $details = [];
        foreach (['status', 'priority', 'snoozed_until', 'tags'] as $key) {
            if (array_key_exists($key, $after)) $details[$key] = is_scalar($after[$key]) || $after[$key] === null ? $after[$key] : (array) $after[$key];
        }
        foreach (['assignee_id', 'team_id'] as $key) {
            if (!array_key_exists($key, $after)) continue;
            $id = (int) ($after[$key] ?? 0);
            $details[$key] = $id > 0 ? $id : null;
            if ($key === 'assignee_id') $details['assignee'] = $id > 0 ? ['id' => $id, 'name' => $assignees[$id] ?? 'Atendente #' . $id] : null;
            if ($key === 'team_id') $details['team'] = $id > 0 ? ['id' => $id, 'name' => $teams[$id] ?? 'Equipe #' . $id] : null;
        }
        return $details;
    }

    private function activityText(string $action, string $actor, array $before, array $after, array $details): string
    {
        $priorityLabels = ['none' => 'Sem prioridade', 'low' => 'Baixa', 'medium' => 'Media', 'normal' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente'];
        $prefix = $actor === 'Sistema' ? 'Sistema' : $actor;
        if ($action === 'conversation.assigned') return $prefix . ' atribuiu a conversa a ' . (string) ($details['assignee']['name'] ?? 'Sem agente');
        if ($action === 'conversation.team_assigned') return $prefix . ' alterou a equipe para ' . (string) ($details['team']['name'] ?? 'Sem equipe');
        if ($action === 'conversation.priority_changed') {
            return $prefix . ' alterou a prioridade de ' . ($priorityLabels[strtolower((string) ($before['priority'] ?? 'none'))] ?? 'Sem prioridade') . ' para ' . ($priorityLabels[strtolower((string) ($after['priority'] ?? 'none'))] ?? 'Sem prioridade');
        }
        if ($action === 'conversation.snoozed') return 'Conversa adiada ate ' . $this->formatActivityDate((string) ($after['snoozed_until'] ?? ''));
        if ($action === 'conversation.pending') return $prefix . ' marcou a conversa como pendente';
        if ($action === 'conversation.resolved') return $prefix . ' resolveu a conversa';
        if ($action === 'conversation.opened') return $prefix . ' abriu a conversa';
        if ($action === 'conversation.read') return $prefix . ' marcou a conversa como lida';
        if ($action === 'conversation.unread') return $prefix . ' marcou a conversa como nao lida';
        if ($action === 'conversation.tags_changed') {
            $tags = is_array($after['tags'] ?? null) ? $after['tags'] : [];
            $labels = [];
            foreach (array_slice($tags, 0, 5) as $tag) {
                if (is_scalar($tag)) {
                    $label = trim((string) $tag);
                    if ($label !== '') $labels[] = mb_substr($label, 0, 80);
                } elseif (is_array($tag) && isset($tag['name']) && is_scalar($tag['name'])) {
                    $label = trim((string) $tag['name']);
                    if ($label !== '') $labels[] = mb_substr($label, 0, 80);
                }
            }
            $suffix = count($tags) > count($labels) ? ' (+' . (count($tags) - count($labels)) . ')' : '';
            return $prefix . ' atualizou as etiquetas para ' . ($labels ? implode(', ', $labels) : 'nenhuma') . $suffix;
        }
        if (in_array($action, ['conversation.bot_paused', 'bot.conversation_paused'], true)) return $prefix . ' pausou o bot para atendimento humano';
        if (in_array($action, ['conversation.bot_resumed', 'bot.conversation_resumed'], true)) return $prefix . ' retomou o bot';
        return 'Atualizacao da conversa';
    }

    private function formatActivityDate(string $value): string
    {
        if ($value === '') return 'data futura';
        try {
            $timezone = new \DateTimeZone((string) $this->settings->get_value('timezone', 'UTC'));
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone($timezone)->format('d/m/Y H:i');
        } catch (Throwable $exception) {
            return 'data futura';
        }
    }
}
