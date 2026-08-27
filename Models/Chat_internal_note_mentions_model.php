<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

class Chat_internal_note_mentions_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_internal_note_mentions';
    protected array $writableFields = [
        'note_id', 'message_id', 'conversation_id', 'mentioned_user_id',
    ];
    protected array $filterableFields = [
        'note_id', 'message_id', 'conversation_id', 'mentioned_user_id',
    ];
}
