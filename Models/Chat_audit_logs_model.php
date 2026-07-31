<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_audit_logs_model extends Chat_domain_model { protected string $logicalTable = 'chat_audit_logs'; protected array $writableFields = ['actor_user_id','action','resource_type','resource_id','instance_id','correlation_id','ip_address','user_agent','before_json','after_json']; protected array $filterableFields = ['actor_user_id','action','resource_type','resource_id','instance_id']; }
