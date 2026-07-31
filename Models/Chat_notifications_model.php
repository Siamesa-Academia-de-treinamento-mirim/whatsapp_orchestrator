<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_notifications_model extends Chat_domain_model { protected string $logicalTable = 'chat_notifications'; protected array $writableFields = ['user_id','kind','level','title','message','resource_type','resource_id','dedupe_key','read_at']; protected array $filterableFields = ['user_id','kind','level','resource_type','resource_id']; }
