<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_ai_agents_model extends Chat_domain_model { protected string $logicalTable = 'chat_ai_agents'; protected array $writableFields = ['name','description','instance_id','workflow_id','webhook_path','default_mode','priority','handoff_policy_json','schedule_json','metadata_json','config_hash','active','created_by']; protected array $filterableFields = ['instance_id','active','created_by']; }
