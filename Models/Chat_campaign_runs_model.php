<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_campaign_runs_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_campaign_runs';
    protected array $writableFields = [
        'campaign_id','external_run_id','occurrence_key','status','metrics_json',
        'scheduled_at','recipient_count','started_at','finished_at','error_message',
    ];
    protected array $filterableFields = ['campaign_id','status','occurrence_key'];
}
