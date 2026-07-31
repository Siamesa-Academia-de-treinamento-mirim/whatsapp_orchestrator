<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Libraries\N8n_client;
use Chatwoot_plugin\Models\Chat_ai_agents_model;
use Chatwoot_plugin\Models\Chat_ai_logs_model;
use Chatwoot_plugin\Models\Chat_ai_states_model;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Ai_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_ai_agents_model $agents = null,
        private ?Chat_ai_states_model $states = null,
        private ?Chat_ai_logs_model $logs = null,
        private ?Chat_conversations_model $conversations = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_settings_model $settings = null,
        private ?N8n_client $n8n = null,
        private ?Audit_service $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->agents ??= new Chat_ai_agents_model();
        $this->states ??= new Chat_ai_states_model();
        $this->logs ??= new Chat_ai_logs_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->n8n ??= new N8n_client($this->settings);
        $this->audit ??= new Audit_service();
        $this->db = $db ?? db_connect('default');
    }

    public function list_agents(array $filters, int $page, int $limit): array
    {
        $result = $this->agents->paginate_records($filters, $page, $limit);
        $result['data'] = array_map([$this, 'mapAgent'], $result['data']);
        return $result;
    }

    public function get_agent(int $id): ?array
    {
        $row = $this->agents->get_by_id($id);
        return $row ? $this->mapAgent($row) : null;
    }

    public function save_agent(array $input, int $actorId, ?int $id = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $webhookPath = $this->normalizeWebhookPath(trim((string) ($input['webhook_path'] ?? $input['webhook_url'] ?? $this->settings->get_value('n8n_ai_path', '/webhook/iara/control'))));
        if ($name === '' || mb_strlen($name) > 191) throw new InvalidArgumentException('Informe um nome de agente valido.');
        if (!$this->safePath($webhookPath)) throw new InvalidArgumentException('Webhook do agente invalido.');
        $instanceId = !empty($input['instance_id']) ? (int) $input['instance_id'] : null;
        if ($instanceId && !$this->instances->get_by_id($instanceId)) throw new InvalidArgumentException('Instancia do agente invalida.');
        $before = $id ? $this->agents->get_by_id($id) : null;
        if ($id && !$before) throw new RuntimeException('Agente nao encontrado.', 404);
        $handoff = $this->arrayValue($input['handoff_policy'] ?? []);
        $schedule = $this->arrayValue($input['schedule'] ?? []);
        $metadata = $this->arrayValue($input['metadata'] ?? []);
        $config = [
            'name' => $name,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000) ?: null,
            'instance_id' => $instanceId,
            'workflow_id' => mb_substr(trim((string) ($input['workflow_id'] ?? '')), 0, 191) ?: null,
            'webhook_path' => $webhookPath,
            'default_mode' => $this->status((string) ($input['default_mode'] ?? 'running')),
            'priority' => (int) ($input['priority'] ?? 0),
            'handoff_policy_json' => json_encode($handoff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'schedule_json' => json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'created_by' => $actorId,
        ];
        $config['config_hash'] = hash('sha256', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($id) $this->agents->update_record($id, $config); else $id = $this->agents->create_record($config);
        $saved = $this->get_agent($id) ?: [];
        $response = $this->n8n->request('POST', $this->aiPath(), ['action' => 'agent.upsert', 'agent' => $saved, 'contract_version' => '1.1.0'], ['idempotency_key' => $config['config_hash'], 'idempotent' => true]);
        $this->recordLog(null, $instanceId, $id, $response['success'] ? 'success' : 'error', 'agent.upsert', $response['correlation_id'], ['agent_id' => $id], $response['data'], $response['error']);
        if (!$response['success']) throw new RuntimeException((string) $response['error'], 502);
        $this->audit->record($actorId, $before ? 'ai_agent.updated' : 'ai_agent.created', 'ai_agent', $id, $instanceId, $before ?: [], $saved, $response['correlation_id']);
        return $saved;
    }

    public function toggle_agent(int $id, int $actorId): array
    {
        $row = $this->agents->get_by_id($id);
        if (!$row) throw new RuntimeException('Agente nao encontrado.', 404);
        $active = empty($row['active']) ? 1 : 0;
        $response = $this->n8n->request('POST', $this->aiPath(), ['action' => 'agent.toggle', 'agent_id' => $id, 'active' => (bool) $active, 'workflow_id' => $row['workflow_id']], ['idempotent' => true]);
        if (!$response['success']) throw new RuntimeException((string) $response['error'], 502);
        $this->agents->update_record($id, ['active' => $active]);
        $this->audit->record($actorId, 'ai_agent.toggled', 'ai_agent', $id, isset($row['instance_id']) ? (int) $row['instance_id'] : null, ['active' => !empty($row['active'])], ['active' => (bool) $active], $response['correlation_id']);
        return $this->get_agent($id) ?: [];
    }

    public function delete_agent(int $id, int $actorId): void
    {
        $row = $this->agents->get_by_id($id);
        if (!$row) throw new RuntimeException('Agente nao encontrado.', 404);
        $response = $this->n8n->request('POST', $this->aiPath(), ['action' => 'agent.delete', 'agent_id' => $id, 'workflow_id' => $row['workflow_id']], ['idempotent' => true]);
        if (!$response['success']) throw new RuntimeException((string) $response['error'], 502);
        $this->agents->soft_delete($id);
        $this->audit->record($actorId, 'ai_agent.deleted', 'ai_agent', $id, isset($row['instance_id']) ? (int) $row['instance_id'] : null, $row, [], $response['correlation_id']);
    }

    public function state(int $conversationId): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new RuntimeException('Conversa nao encontrada.', 404);
        $row = $this->db->table('chat_ai_states')->where('conversation_id', $conversationId)->where('deleted', 0)->get(1)->getRowArray();
        if (!$row) {
            return ['conversation_id' => $conversationId, 'instance_id' => (int) $conversation['instance_id'], 'status' => $this->status((string) $this->settings->get_value('ai_default_state', 'running')), 'reason' => null, 'source' => 'default_policy', 'summary' => (string) ($conversation['ai_summary'] ?? ''), 'last_intent' => null, 'stage' => null, 'handoff_required' => false, 'updated_at' => $conversation['updated_at'] ?? null];
        }
        return $this->mapState($row);
    }

    public function set_state(int $conversationId, array $input, int $actorId, bool $strictExternal = true): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new RuntimeException('Conversa nao encontrada.', 404);
        $status = $this->status((string) ($input['status'] ?? ''));
        $reason = mb_substr(trim((string) ($input['reason'] ?? 'manual')), 0, 191);
        $source = mb_substr(trim((string) ($input['source'] ?? 'rise_plugin')), 0, 64);
        $correlation = trim((string) ($input['correlation_id'] ?? '')) ?: bin2hex(random_bytes(16));
        $existing = $this->db->table('chat_ai_states')->where('conversation_id', $conversationId)->get(1)->getRowArray();
        $payload = [
            'conversation_id' => $conversationId, 'instance_id' => (int) $conversation['instance_id'], 'status' => $status, 'reason' => $reason ?: null, 'source' => $source ?: 'rise_plugin',
            'summary' => isset($input['summary']) ? mb_substr(trim((string) $input['summary']), 0, 20000) : ($existing['summary'] ?? null),
            'last_intent' => isset($input['last_intent']) ? mb_substr(trim((string) $input['last_intent']), 0, 100) : ($existing['last_intent'] ?? null),
            'stage' => isset($input['stage']) ? mb_substr(trim((string) $input['stage']), 0, 100) : ($existing['stage'] ?? null),
            'handoff_required' => filter_var($input['handoff_required'] ?? ($status === 'handoff_pending'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'changed_by' => $actorId ?: null, 'correlation_id' => $correlation,
        ];
        if ($existing && empty($existing['deleted'])) $this->states->update_record((int) $existing['id'], $payload); else $this->states->create_record($payload);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], ['ai_status' => $status, 'ai_summary' => $payload['summary']]);
        try {
            $response = $this->n8n->request('POST', $this->aiPath(), ['action' => 'state.change', 'conversation_id' => $conversationId, 'instance_id' => (int) $conversation['instance_id'], 'remote_jid' => $conversation['remote_jid'], 'status' => $status, 'reason' => $reason, 'source' => $source], ['correlation_id' => $correlation, 'idempotent' => true]);
            if (!$response['success']) throw new RuntimeException((string) $response['error'], 502);
            $current = $this->db->table('chat_ai_states')->where('conversation_id', $conversationId)->where('deleted', 0)->get(1)->getRowArray();
            if ($current) $this->states->update_record((int) $current['id'], ['external_synced_at' => gmdate('Y-m-d H:i:s')]);
            $this->recordLog($conversationId, (int) $conversation['instance_id'], null, 'success', 'state.change', $correlation, $payload, $response['data'], null);
        } catch (Throwable $exception) {
            $this->recordLog($conversationId, (int) $conversation['instance_id'], null, 'error', 'state.change', $correlation, $payload, [], $exception->getMessage());
            if ($strictExternal) throw $exception;
        }
        $result = $this->state($conversationId);
        $this->audit->record($actorId ?: null, 'ai.state_changed', 'conversation', $conversationId, (int) $conversation['instance_id'], $existing ?: [], $result, $correlation);
        if (in_array($status, ['handoff_pending', 'human'], true) && (!is_array($existing) || (string) ($existing['status'] ?? '') !== $status)) {
            try {
                (new Notification_service())->create(
                    'ai_handoff',
                    $status === 'handoff_pending' ? 'Handoff solicitado pela IA' : 'Atendimento assumido por humano',
                    'Conversa #' . $conversationId . ($reason !== '' ? ': ' . $reason : ''),
                    'conversation',
                    $conversationId,
                    $actorId ?: null,
                    $status === 'handoff_pending' ? 'warning' : 'info',
                    'ai-handoff|' . $conversationId . '|' . $correlation
                );
            } catch (Throwable $exception) {
                // State synchronization remains authoritative.
            }
        }
        return $result;
    }

    public function set_instance_state(int $instanceId, array $input, int $actorId): array
    {
        if (!$this->instances->get_by_id($instanceId)) throw new RuntimeException('Instancia nao encontrada.', 404);
        $status = $this->status((string) ($input['status'] ?? ''));
        $response = $this->n8n->request('POST', $this->aiPath(), ['action' => 'instance.state', 'instance_id' => $instanceId, 'status' => $status, 'reason' => (string) ($input['reason'] ?? 'manual'), 'source' => (string) ($input['source'] ?? 'rise_plugin')], ['idempotent' => true]);
        if (!$response['success']) throw new RuntimeException((string) $response['error'], 502);
        $rows = $this->db->table('chat_conversations')->select('id')->where('instance_id', $instanceId)->where('deleted', 0)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->set_state((int) $row['id'], array_merge($input, ['status' => $status, 'correlation_id' => $response['correlation_id']]), $actorId, false);
        }
        return ['instance_id' => $instanceId, 'status' => $status, 'updated_conversations' => count($rows), 'correlation_id' => $response['correlation_id']];
    }

    public function pause_for_human(int $conversationId, int $actorId): void
    {
        if ((string) $this->settings->get_value('ai_human_priority', '1') === '0') return;
        try { $this->set_state($conversationId, ['status' => 'human', 'reason' => 'human_message', 'source' => 'rise_plugin'], $actorId, false); }
        catch (Throwable $exception) { log_message('error', 'Chatwoot_plugin could not pause AI for human message ({exception_type}).', ['exception_type' => get_class($exception)]); }
    }

    public function logs(array $filters, int $page, int $limit): array
    {
        $result = $this->logs->paginate_records($filters, $page, $limit);
        foreach ($result['data'] as &$row) {
            foreach (['request_payload', 'response_payload'] as $field) $row[$field] = $this->json((string) ($row[$field] ?? ''));
            unset($row['deleted']);
        }
        unset($row);
        return $result;
    }

    public function health(): array { return $this->n8n->health(); }

    private function recordLog(?int $conversationId, ?int $instanceId, ?int $agentId, string $status, string $event, string $correlation, array $request, $response, ?string $error): void
    {
        $this->logs->create_record(['conversation_id'=>$conversationId,'instance_id'=>$instanceId,'agent_id'=>$agentId,'status'=>$status,'event_name'=>$event,'correlation_id'=>$correlation,'request_payload'=>json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'response_payload'=>json_encode(is_array($response)?$response:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'error_message'=>$error?mb_substr($error,0,1000):null]);
    }

    private function mapAgent(array $row): array
    {
        return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'description'=>(string)($row['description']??''),'instance_id'=>isset($row['instance_id'])?(int)$row['instance_id']:null,'workflow_id'=>$row['workflow_id']?:null,'webhook_path'=>(string)$row['webhook_path'],'webhook_url'=>$this->publicWebhookUrl((string)$row['webhook_path']),'default_mode'=>(string)$row['default_mode'],'priority'=>(int)$row['priority'],'handoff_policy'=>$this->json((string)($row['handoff_policy_json']??'')),'schedule'=>$this->json((string)($row['schedule_json']??'')),'metadata'=>$this->json((string)($row['metadata_json']??'')),'config_hash'=>(string)$row['config_hash'],'active'=>!empty($row['active']),'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null];
    }

    private function mapState(array $row): array
    {
        return ['conversation_id'=>isset($row['conversation_id'])?(int)$row['conversation_id']:null,'instance_id'=>(int)$row['instance_id'],'status'=>$this->status((string)$row['status']),'reason'=>$row['reason']?:null,'source'=>(string)$row['source'],'summary'=>(string)($row['summary']??''),'last_intent'=>$row['last_intent']?:null,'stage'=>$row['stage']?:null,'handoff_required'=>!empty($row['handoff_required']),'correlation_id'=>$row['correlation_id']?:null,'external_synced_at'=>$row['external_synced_at']?:null,'updated_at'=>$row['updated_at']??null];
    }

    private function status(string $status): string
    {
        $status=strtolower(trim($status)); if(!in_array($status,['running','paused','human','handoff_pending','blocked','error'],true)) throw new InvalidArgumentException('Estado de IA invalido.'); return $status;
    }
    private function aiPath(): string { return '/'.ltrim(trim((string)$this->settings->get_value('n8n_ai_path','/webhook/iara/control')),'/'); }
    private function normalizeWebhookPath(string $value): string { if(str_contains($value,'://')){$parts=parse_url($value);$base=parse_url((string)$this->settings->get_value('n8n_base_url',''));if(!is_array($parts)||!is_array($base)||strtolower((string)($parts['host']??''))!==strtolower((string)($base['host']??'')))throw new InvalidArgumentException('O webhook deve pertencer a origem n8n configurada.');$value=(string)($parts['path']??'');}return$value; }
    private function publicWebhookUrl(string $path): string { $base=rtrim((string)$this->settings->get_value('n8n_base_url',''),'/');return$base!==''?$base.'/'.ltrim($path,'/'):$path; }
    private function safePath(string $path): bool { return str_starts_with($path,'/')&&!str_contains($path,'://')&&!preg_match('/[\r\n?#]/',$path)&&strlen($path)<=500; }
    private function arrayValue($value): array { if(is_array($value))return $value; if(is_string($value)&&trim($value)!==''){ $decoded=json_decode($value,true); if(!is_array($decoded))throw new InvalidArgumentException('JSON de configuracao invalido.'); return $decoded;} return []; }
    private function json(string $value): array { $decoded=json_decode($value,true); return is_array($decoded)?$decoded:[]; }
}
