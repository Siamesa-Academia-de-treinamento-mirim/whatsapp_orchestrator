<?php
declare(strict_types=1);
namespace Chatwoot_plugin\Models;
class Chat_media_model extends Chat_domain_model { protected string $logicalTable = 'chat_media'; protected array $writableFields = ['conversation_id','message_id','instance_id','storage_driver','storage_path','remote_url','original_name','mime_type','media_type','file_size','sha256','external_media_id','created_by','expires_at']; protected array $filterableFields = ['conversation_id','message_id','instance_id','media_type']; }
