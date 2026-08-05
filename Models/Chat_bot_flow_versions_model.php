<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_bot_flow_versions_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_bot_flow_versions';
    protected array $writableFields = [
        'flow_id','version','instance_id','priority','trigger_type','trigger_config_json',
        'definition_json','business_hours_json','fallback_message','handoff_message',
        'max_fallbacks','ignore_groups','published_by','published_at',
    ];
    protected array $filterableFields = ['flow_id','version','instance_id','trigger_type'];

    public function get_published_version(int $flowId, int $version): ?array
    {
        if ($flowId < 1 || $version < 1) return null;
        $row = $this->db->table($this->logicalTable)
            ->where('flow_id', $flowId)
            ->where('version', $version)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();
        return $row ?: null;
    }

    public function latest_published(int $flowId): ?array
    {
        if ($flowId < 1) return null;
        $row = $this->db->table($this->logicalTable)
            ->where('flow_id', $flowId)
            ->where('deleted', 0)
            ->orderBy('version', 'DESC')
            ->get(1)
            ->getRowArray();
        return $row ?: null;
    }

    public function publish_snapshot(array $flow, int $actorId): array
    {
        $flowId = (int) ($flow['id'] ?? 0);
        $version = (int) ($flow['version'] ?? 0);
        $existing = $this->get_published_version($flowId, $version);
        if ($existing) return $existing;

        $id = $this->create_record([
            'flow_id' => $flowId,
            'version' => $version,
            'instance_id' => !empty($flow['instance_id']) ? (int) $flow['instance_id'] : null,
            'priority' => (int) ($flow['priority'] ?? 0),
            'trigger_type' => (string) ($flow['trigger_type'] ?? 'first_message'),
            'trigger_config_json' => $flow['trigger_config_json'] ?? null,
            'definition_json' => (string) ($flow['definition_json'] ?? '{}'),
            'business_hours_json' => $flow['business_hours_json'] ?? null,
            'fallback_message' => (string) ($flow['fallback_message'] ?? ''),
            'handoff_message' => (string) ($flow['handoff_message'] ?? ''),
            'max_fallbacks' => (int) ($flow['max_fallbacks'] ?? 2),
            'ignore_groups' => !empty($flow['ignore_groups']) ? 1 : 0,
            'published_by' => $actorId ?: null,
            'published_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $this->get_by_id($id) ?: [];
    }
}
