<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_internal_notes_model;
use Chatwoot_plugin\Models\Chat_messages_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Chatwoot_plugin\Models\Chat_tags_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

class Conversation_action_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_conversations_model $conversations = null,
        private ?Chat_messages_model $messages = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_internal_notes_model $notes = null,
        private ?Chat_tags_model $tags = null,
        private ?Contact_service $contacts = null,
        private ?Chat_service $chat = null,
        private ?Audit_service $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->conversations ??= new Chat_conversations_model();
        $this->messages ??= new Chat_messages_model();
        $this->instances ??= new Chat_instances_model();
        $this->notes ??= new Chat_internal_notes_model();
        $this->tags ??= new Chat_tags_model();
        $this->contacts ??= new Contact_service();
        $this->chat ??= new Chat_service($this->instances, $this->conversations, $this->messages);
        $this->audit ??= new Audit_service();
        $this->db = $db ?? db_connect('default');
    }

    public function create(array $input, int $actorId): array
    {
        $instanceId = (int) ($input['instance_id'] ?? 0);
        $instance = $this->instances->get_by_id($instanceId);
        if (!$instance || empty($instance['active'])) {
            throw new InvalidArgumentException('Selecione uma instancia ativa.');
        }
        $phone = $this->contacts->normalize_phone((string) ($input['phone'] ?? ''));
        $name = trim((string) ($input['name'] ?? '')) ?: $phone;
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 4096) {
            throw new InvalidArgumentException('A primeira mensagem deve conter entre 1 e 4096 caracteres.');
        }
        $contact = $this->contacts->save(['instance_id' => $instanceId, 'phone' => $phone, 'name' => $name, 'source' => 'manual'], $actorId);
        $jid = $phone . '@s.whatsapp.net';
        $conversationId = $this->conversations->upsert_conversation($instanceId, $jid, [
            'contact_id' => (int) $contact['id'],
            'phone_number' => $phone,
            'contact_name' => $name,
            'status' => (string) (new Chat_settings_model())->get_value('default_status', 'open'),
            'priority' => (string) (new Chat_settings_model())->get_value('default_priority', 'normal'),
        ]);
        $clientId = trim((string) ($input['client_message_id'] ?? '')) ?: 'new-' . bin2hex(random_bytes(12));
        $sent = $this->chat->send_text($conversationId, $message, $clientId, $actorId);
        $result = $this->chat->list_conversations(['instance_id' => $instanceId, 'search' => $phone, 'archived' => false], 1, 10);
        $conversation = null;
        foreach ($result['data'] as $row) {
            if ((int) $row['id'] === $conversationId) {
                $conversation = $row;
                break;
            }
        }
        $this->audit->record($actorId, 'conversation.created', 'conversation', $conversationId, $instanceId, [], ['contact_id' => $contact['id'], 'phone' => $phone]);
        return ['conversation' => $conversation ?: ['id' => $conversationId, 'instance_id' => $instanceId, 'phone' => $phone, 'name' => $name], 'message' => $sent];
    }

    public function add_note(int $conversationId, string $content, int $actorId): array
    {
        $conversation = $this->requireConversation($conversationId);
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new InvalidArgumentException('A nota deve conter entre 1 e 10000 caracteres.');
        }
        $now = time();
        $clientId = 'note-' . bin2hex(random_bytes(12));
        $messageId = $this->messages->upsert_message($conversationId, (int) $conversation['instance_id'], [
            'remote_jid' => (string) $conversation['remote_jid'],
            'direction' => 'internal',
            'message_type' => 'note',
            'text_content' => $content,
            'status' => 'local',
            'sent_at' => gmdate('Y-m-d H:i:s', $now),
            'message_timestamp' => $now,
            'client_message_id' => $clientId,
            'dedupe_key' => hash('sha256', $conversationId . '|' . $clientId),
            'sender_user_id' => $actorId,
            'is_internal_note' => 1,
            'raw_payload' => ['source' => 'rise_internal_note'],
        ]);
        $noteId = $this->notes->create_record(['conversation_id' => $conversationId, 'message_id' => $messageId, 'author_user_id' => $actorId, 'content' => $content]);
        $this->audit->record($actorId, 'conversation.note_created', 'conversation', $conversationId, (int) $conversation['instance_id'], [], ['note_id' => $noteId]);
        return [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'instance_id' => (int) $conversation['instance_id'],
            'direction' => 'internal',
            'message_type' => 'note',
            'text_content' => $content,
            'status' => 'local',
            'sender_user_id' => $actorId,
            'is_internal_note' => true,
            'message_timestamp' => $now,
            'sent_at' => gmdate('c', $now),
        ];
    }

    public function set_priority(int $id, $value, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        if (is_bool($value)) {
            $priority = $value ? 'high' : 'normal';
        } else {
            $priority = strtolower(trim((string) $value));
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException('Prioridade invalida.');
        }
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], ['priority' => $priority]);
        $saved = $this->requireConversation($id);
        $this->audit->record($actorId, 'conversation.priority_changed', 'conversation', $id, (int) $conversation['instance_id'], ['priority' => $conversation['priority'] ?? 'normal'], ['priority' => $priority]);
        return $this->project($saved);
    }

    public function set_status(int $id, string $status, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        if (!in_array($status, ['open', 'pending', 'resolved'], true)) {
            throw new InvalidArgumentException('Status de conversa invalido.');
        }
        $payload = ['status' => $status];
        if ($status === 'resolved') {
            $payload['resolved_at'] = gmdate('Y-m-d H:i:s');
            $payload['resolved_by'] = $actorId;
        } else {
            $payload['resolved_at'] = null;
            $payload['resolved_by'] = null;
        }
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], $payload);
        $saved = $this->requireConversation($id);
        $this->audit->record($actorId, $status === 'resolved' ? 'conversation.resolved' : 'conversation.reopened', 'conversation', $id, (int) $conversation['instance_id'], ['status' => $conversation['status']], ['status' => $status]);
        return $this->project($saved);
    }

    public function set_tags(int $id, array $names, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $normalized = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '' && mb_strlen($name) <= 100) {
                $normalized[mb_strtolower($name)] = $name;
            }
        }
        $normalized = array_values(array_slice($normalized, 0, 50));
        $table = 'chat_conversation_tags';
        $this->db->transStart();
        $this->db->table($table)->where('conversation_id', $id)->update(['deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        foreach ($normalized as $name) {
            $tagId = $this->tags->resolve($name);
            $existing = $this->db->table($table)->where('conversation_id', $id)->where('tag_id', $tagId)->get(1)->getRowArray();
            $data = ['conversation_id' => $id, 'tag_id' => $tagId, 'deleted' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($existing) {
                $this->db->table($table)->where('id', (int) $existing['id'])->update($data);
            } else {
                $data['created_at'] = gmdate('Y-m-d H:i:s');
                $this->db->table($table)->insert($data);
            }
        }
        $this->db->transComplete();
        if (!$this->db->transStatus()) {
            throw new RuntimeException('Nao foi possivel atualizar as tags.');
        }
        $this->audit->record($actorId, 'conversation.tags_changed', 'conversation', $id, (int) $conversation['instance_id'], [], ['tags' => $normalized]);
        return ['id' => $id, 'tags' => $normalized];
    }

    public function assign(int $id, array $input, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $assigneeId = filter_var($input['assign_to_me'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $actorId : (int) ($input['assignee_id'] ?? 0);
        if ($assigneeId > 0) {
            $user = $this->db->table('users')->select('id, first_name, last_name')->where('id', $assigneeId)->where('user_type', 'staff')->where('deleted', 0)->get(1)->getRowArray();
            if (!$user) {
                throw new InvalidArgumentException('Atendente invalido.');
            }
        }
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], ['assignee_id' => $assigneeId > 0 ? $assigneeId : null]);
        $this->audit->record($actorId, 'conversation.assigned', 'conversation', $id, (int) $conversation['instance_id'], ['assignee_id' => $conversation['assignee_id'] ?? null], ['assignee_id' => $assigneeId ?: null]);
        return ['id' => $id, 'assignee_id' => $assigneeId ?: null];
    }

    private function requireConversation(int $id): array
    {
        $row = $this->conversations->get_by_id($id);
        if (!$row) {
            throw new RuntimeException('Conversa nao encontrada.', 404);
        }
        return $row;
    }

    private function project(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'status' => (string) ($row['status'] ?? 'open'),
            'priority' => (string) ($row['priority'] ?? 'normal'),
            'assignee_id' => isset($row['assignee_id']) ? (int) $row['assignee_id'] : null,
            'resolved_at' => $row['resolved_at'] ?? null,
        ];
    }
}
