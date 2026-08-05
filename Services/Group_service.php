<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_contact_identifiers_model;
use Chatwoot_plugin\Models\Chat_contacts_model;
use Chatwoot_plugin\Models\Chat_group_participants_model;
use Chatwoot_plugin\Models\Chat_groups_model;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Keeps group identity and per-message authors separate from the group chat. */
class Group_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_groups_model $groups = null,
        private ?Chat_group_participants_model $participants = null,
        private ?Chat_contacts_model $contacts = null,
        private ?Chat_contact_identifiers_model $identifiers = null,
        ?BaseConnection $db = null
    ) {
        $this->groups ??= new Chat_groups_model();
        $this->participants ??= new Chat_group_participants_model();
        $this->contacts ??= new Chat_contacts_model();
        $this->identifiers ??= new Chat_contact_identifiers_model();
        $this->db = $db ?? db_connect('default');
    }

    /**
     * @return array{group_id:int,participant_id:?int,contact_id:?int,sender_jid:string,sender_phone:string,sender_name:string}
     */
    public function resolve_message_identity(int $instanceId, array $normalized): array
    {
        $remoteJid = trim((string) ($normalized['remote_jid'] ?? ''));
        if ($instanceId < 1 || !str_ends_with(strtolower($remoteJid), '@g.us')) {
            throw new RuntimeException('Mensagem nao pertence a um grupo valido.');
        }
        $subject = trim((string) ($normalized['group_name'] ?? $normalized['contact_name'] ?? ''));
        $groupId = $this->groups->upsert_group($instanceId, $remoteJid, [
            'subject' => $subject !== '' ? mb_substr($subject, 0, 255) : null,
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $senderJid = trim((string) ($normalized['sender_jid'] ?? $normalized['participant_jid'] ?? ''));
        $senderPhone = preg_replace('/\D+/', '', (string) ($normalized['sender_phone'] ?? '')) ?: '';
        $senderName = trim((string) ($normalized['sender_name'] ?? ''));
        $participantId = null;
        $contactId = null;

        if ($senderJid !== '') {
            $contact = null;
            foreach ([['jid', $senderJid], ['phone', $senderPhone]] as [$type, $value]) {
                if ($value === '') continue;
                $identifier = $this->identifiers->find_identifier($instanceId, $type, $value);
                if ($identifier) {
                    $contact = $this->contacts->get_by_id((int) $identifier['contact_id']);
                    break;
                }
            }
            if (!$contact && $senderPhone !== '') {
                $contact = $this->contacts->find_by_phone($senderPhone, $instanceId);
            }
            if (!$contact && $senderPhone !== '' && empty($normalized['from_me'])) {
                try {
                    $contact = (new Contact_service())->resolve_for_participant($instanceId, [
                        'participant_jid' => $senderJid,
                        'sender_phone' => $senderPhone,
                        'sender_name' => $senderName,
                        'timestamp' => $normalized['timestamp'] ?? time(),
                    ]);
                } catch (Throwable $exception) {
                    log_message('warning', 'Could not create group participant contact ({exception_type}).', ['exception_type' => get_class($exception)]);
                }
            }
            $contactId = !empty($contact['id']) ? (int) $contact['id'] : null;
            $participantId = $this->participants->upsert_participant($groupId, $instanceId, $senderJid, [
                'contact_id' => $contactId,
                'phone_normalized' => $senderPhone !== '' ? $senderPhone : null,
                'display_name' => $senderName !== '' ? mb_substr($senderName, 0, 191) : null,
                'is_self' => !empty($normalized['from_me']) ? 1 : 0,
                'active' => 1,
                'last_message_at' => gmdate('Y-m-d H:i:s', (int) ($normalized['timestamp'] ?? time())),
            ]);
        }

        return [
            'group_id' => $groupId,
            'participant_id' => $participantId,
            'contact_id' => $contactId,
            'sender_jid' => $senderJid,
            'sender_phone' => $senderPhone,
            'sender_name' => $senderName,
        ];
    }

    public function get_group_for_conversation(int $conversationId): ?array
    {
        if ($conversationId < 1) return null;
        $row = $this->db->table('chat_conversations')->select('group_id')->where('id', $conversationId)->where('deleted', 0)->get(1)->getRowArray();
        if (!$row || empty($row['group_id'])) return null;
        $group = $this->groups->get_by_id((int) $row['group_id']);
        if (!$group) return null;
        $group['participants'] = $this->participants->list_for_group((int) $group['id']);
        return $group;
    }
}
