<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_bot_sessions_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_bot_sessions';
    protected array $writableFields = [
        'flow_id','flow_version','conversation_id','instance_id','contact_id','current_node_key',
        'status','fallback_count','context_json','last_incoming_message_id','last_outgoing_message_id',
        'handoff_reason','started_at','last_activity_at','ended_at',
    ];
    protected array $filterableFields = ['flow_id','conversation_id','instance_id','status'];

    public function get_by_conversation(int $conversationId): ?array
    {
        if ($conversationId < 1) return null;
        $row = $this->db->table($this->logicalTable)->where('conversation_id', $conversationId)->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }
}
