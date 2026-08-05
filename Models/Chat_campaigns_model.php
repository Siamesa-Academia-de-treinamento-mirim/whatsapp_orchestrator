<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_campaigns_model extends Chat_domain_model { protected string $logicalTable = 'chat_campaigns'; protected array $writableFields = ['instance_id','external_id','name','description','status','audience_json','message_content','media_id','schedule_json','metrics_json','correlation_id','idempotency_key','last_error','last_sync_at','created_by','campaign_type','template_id','template_parameters_json','dispatch_mode','rate_limit_per_minute','started_at','finished_at']; protected array $filterableFields = ['instance_id','status','created_by','campaign_type','template_id','template_parameters_json','dispatch_mode','rate_limit_per_minute','started_at','finished_at']; }
