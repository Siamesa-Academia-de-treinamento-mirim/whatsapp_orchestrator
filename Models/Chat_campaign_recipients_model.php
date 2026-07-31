<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_campaign_recipients_model extends Chat_domain_model { protected string $logicalTable = 'chat_campaign_recipients'; protected array $writableFields = ['campaign_id','contact_id','phone_hash','phone_normalized','status','external_message_id','error_message','sent_at']; protected array $filterableFields = ['campaign_id','contact_id','status']; }
