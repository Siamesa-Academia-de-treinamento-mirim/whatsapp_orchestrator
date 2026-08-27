<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_campaign_templates_model;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

/** Local Meta template cache and provider-neutral template DTO boundary. */
class Official_template_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_instances_model $instances = null,
        private ?Provider_manager $providers = null,
        private ?Chat_campaign_templates_model $templates = null,
        ?BaseConnection $db = null,
        private ?Chat_conversations_model $conversations = null,
        private ?Template_parser_service $parser = null,
        private ?Send_lock_service $locks = null
    ) {
        $this->instances ??= new Chat_instances_model();
        $this->providers ??= new Provider_manager($this->instances);
        $this->templates ??= new Chat_campaign_templates_model();
        $this->db = $db ?? db_connect('default');
        $this->conversations ??= new Chat_conversations_model();
        $this->parser ??= new Template_parser_service();
        $this->locks ??= new Send_lock_service($this->db);
    }

    /** @return array<string,mixed> */
    public function sync(int $instanceId): array
    {
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance) throw new InvalidArgumentException('Instancia nao encontrada.');
        $capabilities = \Chatwoot_plugin\Providers\Provider_capabilities::forProvider((string) ($instance['provider_type'] ?? ''));
        if (empty($capabilities['actions']['send_template'])) throw new Message_send_exception('Este provedor nao suporta templates oficiais.', 'rejected', 422, null, 'TEMPLATES_NOT_SUPPORTED', ['provider' => (string) ($instance['provider_type'] ?? '')]);
        $lock = 'chat_official_templates_' . $instanceId;
        if (!$this->locks->acquire($lock, 0)) throw new RuntimeException('A sincronizacao de templates ja esta em andamento.', 409);
        try {
            $rows = $this->fetchAll($this->providers->forInstance($instance));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->resetTransStatus();
            if (!$this->db->transBegin()) throw new RuntimeException('Nao foi possivel iniciar a sincronizacao de templates.');
            try {
                $seen = [];
                $synced = 0;
                foreach ($rows as $row) {
                    $payload = $this->payload($instanceId, $row, $now);
                    if ($payload === null) continue;
                    $seen[] = $payload['provider_template_id'];
                    $existing = $this->db->table('chat_campaign_templates')->select('id')->where('instance_id', $instanceId)->where('provider_template_id', $payload['provider_template_id'])->where('deleted', 0)->get(1)->getRowArray();
                    if ($existing) $this->db->table('chat_campaign_templates')->where('id', (int) $existing['id'])->update($payload);
                    else { $payload['created_at'] = $now; $this->db->table('chat_campaign_templates')->insert($payload); }
                    ++$synced;
                }
                $staleQuery = $this->db->table('chat_campaign_templates')->where('instance_id', $instanceId)->where('deleted', 0);
                if ($seen !== []) $staleQuery->whereNotIn('provider_template_id', $seen);
                $staleQuery->update(['active' => 0, 'provider_status' => 'stale', 'updated_at' => $now]);
                if (!$this->db->transStatus()) throw new RuntimeException('A sincronizacao de templates falhou.');
                $this->db->transCommit();
            } catch (\Throwable $exception) {
                $this->db->transRollback();
                throw $exception;
            }
            return ['instance_id' => $instanceId, 'synced' => $synced, 'templates' => $this->list($instanceId)];
        } finally {
            $this->locks->release($lock);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $instanceId): array
    {
        if ($instanceId < 1) return [];
        $rows = $this->db->table('chat_campaign_templates')->where('instance_id', $instanceId)->where('deleted', 0)->orderBy('name', 'ASC')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['active'] = !empty($row['active']);
            $row['components'] = json_decode((string) ($row['components_json'] ?? '[]'), true) ?: [];
            unset($row['components_json']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForConversation(int $conversationId, array $filters = []): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new InvalidArgumentException('Conversa nao encontrada.');
        $instance = $this->instances->get_by_id((int) ($conversation['instance_id'] ?? 0));
        $provider = strtolower((string) ($instance['provider_type'] ?? ''));
        $capabilities = \Chatwoot_plugin\Providers\Provider_capabilities::forProvider($provider);
        if (empty($capabilities['actions']['send_template'])) {
            throw new Message_send_exception(
                'Este provedor nao suporta templates oficiais.',
                'rejected',
                422,
                null,
                'TEMPLATES_NOT_SUPPORTED',
                ['provider' => $provider]
            );
        }
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $language = trim((string) ($filters['language'] ?? ''));
        $category = strtolower(trim((string) ($filters['category'] ?? '')));
        $result = [];
        foreach ($this->list((int) $instance['id']) as $row) {
            if (empty($row['active']) || strtolower((string) ($row['provider_status'] ?? '')) !== 'approved') continue;
            if ($language !== '' && (string) ($row['language_code'] ?? '') !== $language) continue;
            if ($category !== '' && strtolower((string) ($row['category'] ?? '')) !== $category) continue;
            if ($search !== '' && !str_contains(strtolower((string) $row['name'] . ' ' . $row['message_content']), $search)) continue;
            $result[] = $this->parser->parse($row);
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function templateForConversation(int $conversationId, int $templateId): ?array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation || $templateId < 1) return null;
        $row = $this->db->table('chat_campaign_templates')->where('id', $templateId)->where('instance_id', (int) ($conversation['instance_id'] ?? 0))->where('deleted', 0)->get(1)->getRowArray();
        if (!$row) return null;
        return $this->parser->parse($this->decodeRow($row));
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchAll($provider): array
    {
        if (!method_exists($provider, 'listTemplatesPage')) {
            $response = $provider->listTemplates(250);
            if (empty($response['success'])) throw new RuntimeException((string) ($response['error'] ?? 'Falha ao sincronizar templates.'));
            return is_array($response['data']['data'] ?? null) ? $response['data']['data'] : [];
        }
        $rows = [];
        $after = null;
        $seenCursors = [];
        for ($page = 0; $page < 20 && count($rows) <= 5000; ++$page) {
            $response = $provider->listTemplatesPage(250, $after);
            if (empty($response['success'])) throw new RuntimeException((string) ($response['error'] ?? 'Falha ao sincronizar templates.'));
            $data = $response['data'] ?? [];
            if (!is_array($data) || !is_array($data['data'] ?? null)) throw new RuntimeException('Resposta de templates Meta invalida.');
            foreach ($data['data'] as $row) if (is_array($row)) $rows[] = $row;
            $next = trim((string) ($data['paging']['cursors']['after'] ?? ''));
            if ($next === '') return $rows;
            if (!preg_match('/^[A-Za-z0-9._:=-]{1,512}$/', $next) || isset($seenCursors[$next])) throw new RuntimeException('Paginacao de templates Meta invalida.');
            $seenCursors[$next] = true;
            $after = $next;
        }
        throw new RuntimeException('Sincronizacao de templates excedeu o limite seguro de paginas.');
    }

    /** @return array<string,mixed>|null */
    private function payload(int $instanceId, array $row, string $now): ?array
    {
        $providerId = trim((string) ($row['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($providerId === '' || $name === '') return null;
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];
        return [
            'instance_id' => $instanceId,
            'provider_template_id' => mb_substr($providerId, 0, 191),
            'name' => mb_substr($name, 0, 191),
            'message_content' => $this->bodyText($components),
            'language_code' => mb_substr((string) ($row['language'] ?? 'pt_BR'), 0, 20),
            'category' => mb_substr((string) ($row['category'] ?? ''), 0, 32) ?: null,
            'provider_status' => mb_substr(strtolower((string) ($row['status'] ?? 'unknown')), 0, 32),
            'components_json' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => $now,
            'active' => strtolower((string) ($row['status'] ?? '')) === 'approved' ? 1 : 0,
            'updated_at' => $now,
            'deleted' => 0,
        ];
    }

    private function bodyText(array $components): string
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') return mb_substr(trim((string) ($component['text'] ?? '')), 0, 10000);
        }
        return '';
    }

    private function decodeRow(array $row): array
    {
        $row['components'] = json_decode((string) ($row['components_json'] ?? '[]'), true) ?: [];
        return $row;
    }
}
