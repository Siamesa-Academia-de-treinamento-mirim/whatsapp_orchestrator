<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_internal_notes_model extends Chat_domain_model { protected string $logicalTable = 'chat_internal_notes'; protected array $writableFields = ['conversation_id','message_id','author_user_id','content']; protected array $filterableFields = ['conversation_id','author_user_id']; }
