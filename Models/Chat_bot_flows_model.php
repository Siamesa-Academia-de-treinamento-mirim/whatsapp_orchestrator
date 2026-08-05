<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_bot_flows_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_bot_flows';
    protected array $writableFields = [
        'instance_id','name','description','version','status','priority','trigger_type',
        'trigger_config_json','definition_json','business_hours_json','fallback_message',
        'handoff_message','max_fallbacks','ignore_groups','active','created_by',
        'published_by','published_at',
    ];
    protected array $filterableFields = ['instance_id','status','active','trigger_type'];
}
