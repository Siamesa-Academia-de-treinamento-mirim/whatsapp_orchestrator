<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_media_model extends Chat_domain_model { protected string $logicalTable = 'chat_media'; protected array $writableFields = ['conversation_id','message_id','instance_id','storage_driver','storage_path','remote_url','original_name','mime_type','media_type','file_size','sha256','external_media_id','created_by','expires_at']; protected array $filterableFields = ['conversation_id','message_id','instance_id','media_type'];
    public function findOwnedTemplateMedia(int $mediaId, int $conversationId, int $instanceId): ?array
    {
        if ($mediaId < 1 || $conversationId < 1 || $instanceId < 1) return null;
        return $this->db->table($this->logicalTable)
            ->where('id', $mediaId)->where('conversation_id', $conversationId)
            ->where('instance_id', $instanceId)->where('deleted', 0)
            ->get(1)->getRowArray() ?: null;
    }
}
