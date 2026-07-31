<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_ai_states_model extends Chat_domain_model { protected string $logicalTable = 'chat_ai_states'; protected array $writableFields = ['conversation_id','instance_id','status','reason','source','summary','last_intent','stage','handoff_required','changed_by','correlation_id','external_synced_at']; protected array $filterableFields = ['conversation_id','instance_id','status']; }
