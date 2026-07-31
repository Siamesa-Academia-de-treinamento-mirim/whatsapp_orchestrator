<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_automations_model extends Chat_domain_model { protected string $logicalTable = 'chat_automations'; protected array $writableFields = ['name','trigger_event','conditions_json','webhook_path','instance_id','delay_seconds','active','last_run_at','last_status','last_error','created_by']; protected array $filterableFields = ['instance_id','active','trigger_event','created_by']; }
