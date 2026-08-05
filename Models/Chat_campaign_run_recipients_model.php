<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_campaign_run_recipients_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_campaign_run_recipients';
    protected array $writableFields = [
        'run_id','campaign_id','audience_recipient_id','contact_id','phone_hash',
        'phone_normalized','variables_json','status','attempts','max_attempts',
        'available_at','queued_at','last_attempt_at','external_message_id','error_message',
        'sent_at','delivered_at','read_at','replied_at',
    ];
    protected array $filterableFields = [
        'run_id','campaign_id','audience_recipient_id','contact_id','status',
    ];
}
