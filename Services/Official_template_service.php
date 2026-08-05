<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_campaign_templates_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

class Official_template_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_instances_model $instances = null,
        private ?Provider_manager $providers = null,
        private ?Chat_campaign_templates_model $templates = null,
        ?BaseConnection $db = null
    ) {
        $this->instances ??= new Chat_instances_model();
        $this->providers ??= new Provider_manager($this->instances);
        $this->templates ??= new Chat_campaign_templates_model();
        $this->db = $db ?? db_connect('default');
    }

    /** @return array<string,mixed> */
    public function sync(int $instanceId): array
    {
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance) throw new InvalidArgumentException('Instancia nao encontrada.');
        if (($instance['provider_type'] ?? '') !== 'meta_cloud') throw new InvalidArgumentException('A instancia nao usa a API oficial.');
        $response = $this->providers->forInstance($instance)->listTemplates(250);
        if (empty($response['success'])) throw new RuntimeException((string) ($response['error'] ?? 'Falha ao sincronizar templates.'));
        $rows = $response['data']['data'] ?? [];
        if (!is_array($rows)) $rows = [];
        $synced = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $providerId = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $language = trim((string) ($row['language'] ?? 'pt_BR'));
            if ($providerId === '' || $name === '') continue;
            $existing = $this->db->table('chat_campaign_templates')
                ->select('id')->where('instance_id', $instanceId)
                ->where('provider_template_id', $providerId)->where('deleted', 0)->get(1)->getRowArray();
            $payload = [
                'instance_id' => $instanceId,
                'provider_template_id' => $providerId,
                'name' => mb_substr($name, 0, 191),
                'message_content' => $this->bodyText((array) ($row['components'] ?? [])),
                'language_code' => mb_substr($language, 0, 20),
                'category' => mb_substr((string) ($row['category'] ?? ''), 0, 32) ?: null,
                'provider_status' => mb_substr(strtolower((string) ($row['status'] ?? 'unknown')), 0, 32),
                'components_json' => json_encode($row['components'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_synced_at' => $now,
                'active' => strtolower((string) ($row['status'] ?? '')) === 'approved' ? 1 : 0,
                'updated_at' => $now,
                'deleted' => 0,
            ];
            if ($existing) $this->db->table('chat_campaign_templates')->where('id', (int) $existing['id'])->update($payload);
            else { $payload['created_at'] = $now; $this->db->table('chat_campaign_templates')->insert($payload); }
            $synced++;
        }
        return ['instance_id' => $instanceId, 'synced' => $synced, 'templates' => $this->list($instanceId)];
    }

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

    private function bodyText(array $components): string
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return mb_substr(trim((string) ($component['text'] ?? '')), 0, 10000);
            }
        }
        return '';
    }
}
