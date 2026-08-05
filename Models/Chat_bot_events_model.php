<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_bot_events_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_bot_events';
    protected array $writableFields = [
        'session_id','flow_id','conversation_id','message_id','event_type','node_key',
        'matched_transition','input_preview','output_preview','metadata_json',
    ];
    protected array $filterableFields = ['session_id','flow_id','conversation_id','event_type'];
}
