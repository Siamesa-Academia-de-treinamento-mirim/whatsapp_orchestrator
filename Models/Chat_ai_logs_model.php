<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_ai_logs_model extends Chat_domain_model { protected string $logicalTable = 'chat_ai_logs'; protected array $writableFields = ['conversation_id','instance_id','agent_id','status','event_name','correlation_id','request_payload','response_payload','error_message']; protected array $filterableFields = ['conversation_id','instance_id','agent_id','status','correlation_id']; }
