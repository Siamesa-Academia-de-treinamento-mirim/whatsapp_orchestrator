<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_quick_replies_model extends Chat_domain_model { protected string $logicalTable = 'chat_quick_replies'; protected array $writableFields = ['title','text_content','shortcut','scope_type','scope_id','sort_order','active','created_by']; protected array $filterableFields = ['active','scope_type','scope_id']; }
