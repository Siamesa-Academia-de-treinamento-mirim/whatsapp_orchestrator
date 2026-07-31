<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_campaign_templates_model extends Chat_domain_model { protected string $logicalTable = 'chat_campaign_templates'; protected array $writableFields = ['name','message_content','media_id','active','created_by']; protected array $filterableFields = ['active','created_by']; }
