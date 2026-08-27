<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use RuntimeException;

class Chat_conversation_presence_model extends Chat_domain_model
{
    protected string $logicalTable = 'chat_conversation_presence';
    protected array $writableFields = [
        'conversation_id', 'user_id', 'viewing', 'typing_until',
        'last_seen_at', 'expires_at',
    ];
    protected array $filterableFields = ['conversation_id', 'user_id', 'viewing'];

    /** Presence schema is intentionally ephemeral and has no created_at column. */
    public function create_record(array $data): int
    {
        $payload = array_intersect_key($data, array_flip($this->writableFields));
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;
        if (!$this->db->table($this->logicalTable)->insert($payload)) {
            throw new RuntimeException('Unable to create record in ' . $this->logicalTable . '.');
        }
        return (int) $this->db->insertID();
    }
}
