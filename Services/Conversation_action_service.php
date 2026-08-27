<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_internal_notes_model;
use Chatwoot_plugin\Models\Chat_internal_note_mentions_model;
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
        ?BaseConnection $db = null,
        private ?Chat_internal_note_mentions_model $noteMentions = null,
        private ?Notification_service $notifications = null,
        private ?Send_lock_service $sendLocks = null
    ) {
        $this->conversations ??= new Chat_conversations_model();
        $this->messages ??= new Chat_messages_model();
        $this->instances ??= new Chat_instances_model();
        $this->notes ??= new Chat_internal_notes_model();
        $this->noteMentions ??= new Chat_internal_note_mentions_model();
        $this->tags ??= new Chat_tags_model();
        $this->contacts ??= new Contact_service();
        $this->chat ??= new Chat_service($this->instances, $this->conversations, $this->messages);
        $this->audit ??= new Audit_service();
        $this->notifications ??= new Notification_service();
        $this->db = $db ?? db_connect('default');
        $this->sendLocks ??= new Send_lock_service($this->db);
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
            'priority' => Conversation_workflow_service::canonicalPriority((new Chat_settings_model())->get_value('default_priority', 'medium')),
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

    public function add_note(int $conversationId, string $content, int $actorId, string $clientMessageId = '', array $mentionUserIds = []): array
    {
        $conversation = $this->requireConversation($conversationId);
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new InvalidArgumentException('A nota deve conter entre 1 e 10000 caracteres.');
        }
        $now = time();
        $clientId = trim($clientMessageId);
        if ($clientId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $clientId)) {
            throw new InvalidArgumentException('Identificador idempotente da nota invalido.');
        }
        $clientId = $clientId !== '' ? $clientId : 'note-' . bin2hex(random_bytes(12));
        $mentionUserIds = $this->validateMentionUserIds($mentionUserIds);
        $locked = false;
        try {
            if (!$this->sendLocks->acquireFor($conversationId, $clientId, 0)) {
                throw new RuntimeException('Esta nota ja esta sendo processada.', 409);
            }
            $locked = true;
            $existing = $this->messages->find_by_client_message_id($conversationId, $clientId);
            if ($existing) {
                if (!$this->isInternalNoteMessage($existing)) {
                    throw new Message_send_exception(
                        'O identificador da nota ja pertence a outro tipo de mensagem.',
                        'rejected',
                        409,
                        null,
                        'IDEMPOTENCY_PAYLOAD_MISMATCH'
                    );
                }
                $raw = json_decode((string) ($existing['raw_payload'] ?? ''), true);
                $storedMentions = $this->normalizeMentionIds(is_array($raw) ? ($raw['mention_user_ids'] ?? []) : []);
                if ((string) ($existing['text_content'] ?? '') !== $content || $storedMentions !== $mentionUserIds) {
                    throw new Message_send_exception(
                        'O payload da nota nao corresponde ao identificador ja persistido.',
                        'rejected',
                        409,
                        null,
                        'IDEMPOTENCY_PAYLOAD_MISMATCH'
                    );
                }
                return [
                    'id' => (int) $existing['id'],
                    'conversation_id' => (int) $existing['conversation_id'],
                    'instance_id' => (int) $existing['instance_id'],
                    'direction' => 'internal',
                    'message_type' => 'note',
                    'text_content' => (string) ($existing['text_content'] ?? $content),
                    'status' => 'local',
                    'sender_user_id' => (int) ($existing['sender_user_id'] ?? $actorId),
                    'is_internal_note' => true,
                    'client_message_id' => $clientId,
                    'message_timestamp' => (int) ($existing['message_timestamp'] ?? 0),
                    'sent_at' => (string) ($existing['sent_at'] ?? ''),
                    'idempotency_state' => 'idempotent_success',
                    'mentions' => $this->mentionRows((int) $existing['id']),
                ];
            }
            $this->db->transStart();
            try {
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
                    'raw_payload' => ['source' => 'rise_internal_note', 'mention_user_ids' => $mentionUserIds],
                ]);
                $noteId = $this->notes->create_record(['conversation_id' => $conversationId, 'message_id' => $messageId, 'author_user_id' => $actorId, 'content' => $content]);
                foreach ($mentionUserIds as $mentionedUserId) {
                    $this->noteMentions->create_record([
                        'note_id' => $noteId,
                        'message_id' => $messageId,
                        'conversation_id' => $conversationId,
                        'mentioned_user_id' => $mentionedUserId,
                    ]);
                }
                $this->db->transComplete();
            } catch (\Throwable $exception) {
                $this->db->transRollback();
                throw $exception;
            }
            if (!$this->db->transStatus()) throw new RuntimeException('Nao foi possivel persistir a nota e suas mencoes.');
            $this->audit->record($actorId, 'conversation.note_created', 'conversation', $conversationId, (int) $conversation['instance_id'], [], ['note_id' => $noteId]);
            $actor = $this->db->table('users')->select('first_name, last_name')->where('id', $actorId)->get(1)->getRowArray();
            $actorName = trim((string) ($actor['first_name'] ?? '') . ' ' . (string) ($actor['last_name'] ?? '')) ?: 'Um agente';
            foreach ($mentionUserIds as $mentionedUserId) {
                if ($mentionedUserId === $actorId) continue;
                try {
                    $this->notifications->create('mention', 'Nova mencao', $actorName . ' mencionou voce em uma nota interna.', 'conversation', $conversationId, $mentionedUserId, 'info', 'note-mention|' . $noteId . '|' . $mentionedUserId);
                } catch (\Throwable $exception) {
                    log_message('error', 'Chatwoot_plugin mention notification failed ({exception_type}).', ['exception_type' => get_class($exception)]);
                }
            }
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
                'mentions' => $this->mentionRows($messageId),
            ];
        } finally {
            if ($locked) $this->sendLocks->releaseFor($conversationId, $clientId);
        }
    }

    private function isInternalNoteMessage(array $message): bool
    {
        $isInternal = $message['is_internal_note'] ?? false;
        $isInternal = $isInternal === true || (is_numeric($isInternal) && (int) $isInternal === 1);
        $direction = strtolower(trim((string) ($message['direction'] ?? '')));
        $messageType = strtolower(trim((string) ($message['message_type'] ?? '')));

        return $isInternal
            && in_array($direction, ['internal', 'interna'], true)
            && in_array($messageType, ['note', 'internal_note'], true);
    }

    /** @return array<int,int> */
    private function validateMentionUserIds(array $ids): array
    {
        $ids = $this->normalizeMentionIds($ids);
        if (count($ids) > 20) throw new InvalidArgumentException('Uma nota pode mencionar no maximo 20 agentes.');
        if (!$ids) return [];
        $rows = $this->db->table('users')->select('id')->whereIn('id', $ids)->where('user_type', 'staff')->where('status', 'active')->where('deleted', 0)->get()->getResultArray();
        $valid = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if (count($valid) !== count($ids)) throw new InvalidArgumentException('Uma ou mais mencoes apontam para agente invalido.');
        sort($valid, SORT_NUMERIC);
        return $valid;
    }

    /** @return array<int,int> */
    private function normalizeMentionIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return array_slice($ids, 0, 21);
    }

    /** @return array<int,array{id:int,name:string,avatar:string}> */
    private function mentionRows(int $messageId): array
    {
        if ($messageId < 1 || !$this->db->tableExists($this->db->prefixTable('chat_internal_note_mentions'), false)) return [];
        $rows = $this->db->table('chat_internal_note_mentions m')
            ->select('m.mentioned_user_id, u.first_name, u.last_name')
            ->join('users u', 'u.id = m.mentioned_user_id', 'inner')
            ->where('m.message_id', $messageId)
            ->where('m.deleted', 0)
            ->where('u.deleted', 0)
            ->get()->getResultArray();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['mentioned_user_id'],
            'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'avatar' => '',
        ], $rows);
    }

    public function set_priority(int $id, $value, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $priority = Conversation_workflow_service::validatePriority($value);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], ['priority' => $priority]);
        $saved = $this->requireConversation($id);
        $this->audit->record($actorId, 'conversation.priority_changed', 'conversation', $id, (int) $conversation['instance_id'], ['priority' => Conversation_workflow_service::canonicalPriority($conversation['priority'] ?? 'none')], ['priority' => $priority]);
        return $this->project($saved);
    }

    public function set_status(int $id, string $status, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $status = Conversation_workflow_service::validateStatus($status);
        if ($status === 'snoozed') throw new InvalidArgumentException('Use o endpoint de snooze para suspender a conversa.');
        $payload = ['status' => $status];
        $payload['snoozed_until'] = null;
        $payload['snoozed_by'] = null;
        if ($status === 'resolved') {
            $payload['resolved_at'] = gmdate('Y-m-d H:i:s');
            $payload['resolved_by'] = $actorId;
        } else {
            $payload['resolved_at'] = null;
            $payload['resolved_by'] = null;
        }
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], $payload);
        $saved = $this->requireConversation($id);
        $action = $status === 'resolved' ? 'conversation.resolved' : ($status === 'pending' ? 'conversation.pending' : 'conversation.opened');
        $this->audit->record($actorId, $action, 'conversation', $id, (int) $conversation['instance_id'], ['status' => $conversation['status']], ['status' => $status]);
        return $this->project($saved);
    }

    public function snooze(int $id, string $until, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        if (!in_array((string) ($conversation['status'] ?? 'open'), ['open', 'pending'], true)) {
            throw new InvalidArgumentException('Somente conversas abertas ou pendentes podem ser adiadas.');
        }
        $snoozedUntil = Conversation_workflow_service::snoozeUntil($until);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], [
            'status' => 'snoozed',
            'snoozed_until' => $snoozedUntil,
            'snoozed_by' => $actorId,
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
        $this->audit->record($actorId, 'conversation.snoozed', 'conversation', $id, (int) $conversation['instance_id'], ['status' => $conversation['status']], ['status' => 'snoozed', 'snoozed_until' => $snoozedUntil]);
        return $this->project($this->requireConversation($id));
    }

    public function unsnooze(int $id, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        if (($conversation['status'] ?? '') !== 'snoozed') return $this->project($conversation);
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], [
            'status' => 'open', 'snoozed_until' => null, 'snoozed_by' => null,
        ]);
        $this->audit->record($actorId, 'conversation.opened', 'conversation', $id, (int) $conversation['instance_id'], ['status' => 'snoozed'], ['status' => 'open', 'reason' => 'unsnooze']);
        return $this->project($this->requireConversation($id));
    }

    public function mark_read(int $id, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $previousUnread = (int) ($conversation['unread_count'] ?? 0);
        $this->conversations->mark_read($id);
        if ($previousUnread > 0) {
            $this->audit->record($actorId, 'conversation.read', 'conversation', $id, (int) $conversation['instance_id'], ['unread_count' => $previousUnread], ['unread_count' => 0]);
        }
        return $this->project($this->requireConversation($id));
    }

    public function mark_unread(int $id, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        $previousUnread = (int) ($conversation['unread_count'] ?? 0);
        $this->conversations->mark_unread($id);
        if ($previousUnread < 1) {
            $this->audit->record($actorId, 'conversation.unread', 'conversation', $id, (int) $conversation['instance_id'], ['unread_count' => $previousUnread], ['unread_count' => 1]);
        }
        return $this->project($this->requireConversation($id));
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
        return $this->project($this->requireConversation($id));
    }

    public function add_tags(int $id, array $names, int $actorId): array
    {
        $current = $this->project($this->requireConversation($id));
        $existing = is_array($current['tags'] ?? null) ? $current['tags'] : [];
        return $this->set_tags($id, array_merge($existing, $names), $actorId);
    }

    public function remove_tags(int $id, array $names, int $actorId): array
    {
        $current = $this->project($this->requireConversation($id));
        $remove = array_fill_keys(array_map(static fn ($name): string => mb_strtolower(trim((string) $name)), $names), true);
        $existing = array_values(array_filter(is_array($current['tags'] ?? null) ? $current['tags'] : [], static fn ($name): bool => !isset($remove[mb_strtolower(trim((string) $name))])));
        return $this->set_tags($id, $existing, $actorId);
    }

    public function assign(int $id, array $input, int $actorId): array
    {
        $conversation = $this->requireConversation($id);
        // Policy: assignee and team are independent workflow fields. The host
        // team domain is a grouping boundary, not a hard membership constraint;
        // assignment never auto-round-robins or silently changes the other field.
        $assigneeTouched = array_key_exists('assignee_id', $input) || array_key_exists('assign_to_me', $input);
        $teamTouched = array_key_exists('team_id', $input);
        if (!$assigneeTouched && !$teamTouched) {
            throw new InvalidArgumentException('Informe ao menos um campo de atribuicao.');
        }

        $assigneeId = (int) ($conversation['assignee_id'] ?? 0);
        if ($assigneeTouched) {
            $assignToMe = array_key_exists('assign_to_me', $input)
                && filter_var($input['assign_to_me'], FILTER_VALIDATE_BOOLEAN);
            $assigneeId = $assignToMe ? $actorId : (int) ($input['assignee_id'] ?? 0);
        }
        if ($assigneeTouched && $assigneeId > 0) {
            $user = $this->db->table('users')->select('id, first_name, last_name')->where('id', $assigneeId)->where('user_type', 'staff')->where('status', 'active')->where('deleted', 0)->get(1)->getRowArray();
            if (!$user) {
                throw new InvalidArgumentException('Atendente invalido.');
            }
        }
        $teamId = (int) ($conversation['team_id'] ?? 0);
        if ($teamTouched) {
            $teamId = (int) ($input['team_id'] ?? 0);
        }
        if ($teamTouched && $teamId > 0 && !(new Conversation_workflow_service($this->db))->teamExists($teamId)) {
            throw new InvalidArgumentException('Equipe invalida.');
        }

        $payload = [];
        if ($assigneeTouched) $payload['assignee_id'] = $assigneeId > 0 ? $assigneeId : null;
        if ($teamTouched) $payload['team_id'] = $teamId > 0 ? $teamId : null;
        $this->conversations->upsert_conversation((int) $conversation['instance_id'], (string) $conversation['remote_jid'], $payload);
        if ($assigneeTouched && (int) ($conversation['assignee_id'] ?? 0) !== $assigneeId) {
            $this->audit->record($actorId, 'conversation.assigned', 'conversation', $id, (int) $conversation['instance_id'], ['assignee_id' => $conversation['assignee_id'] ?? null], ['assignee_id' => $assigneeId ?: null]);
        }
        if ($teamTouched && (int) ($conversation['team_id'] ?? 0) !== $teamId) {
            $this->audit->record($actorId, 'conversation.team_assigned', 'conversation', $id, (int) $conversation['instance_id'], ['team_id' => $conversation['team_id'] ?? null], ['team_id' => $teamId ?: null]);
        }
        return $this->project($this->requireConversation($id));
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
        $projected = $this->chat->get_conversation((int) ($row['id'] ?? 0));
        if ($projected) return $projected;
        return [
            'id' => (int) $row['id'],
            'status' => (string) ($row['status'] ?? 'open'),
            'priority' => Conversation_workflow_service::canonicalPriority($row['priority'] ?? 'none'),
            'assignee_id' => isset($row['assignee_id']) ? (int) $row['assignee_id'] : null,
            'resolved_at' => $row['resolved_at'] ?? null,
        ];
    }
}
