<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Safely repairs names that were overwritten by outgoing pushName values. */
class Contact_repair_service
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db = null) { $this->db = $db ?? db_connect('default'); }

    /** @return array{suspect_name:string,count:int,proposals:array<int,array<string,mixed>>} */
    public function preview(string $suspectName = 'Tiago', int $limit = 500): array
    {
        $suspectName = trim($suspectName);
        if ($suspectName === '' || mb_strlen($suspectName) > 191) throw new InvalidArgumentException('Nome suspeito invalido.');
        $limit = min(2000, max(1, $limit));
        $contacts = $this->db->table('chat_contacts')
            ->where('deleted', 0)->where('manually_edited', 0)
            ->where('LOWER(TRIM(name))', mb_strtolower($suspectName))
            ->orderBy('last_activity_at', 'DESC')->limit($limit)->get()->getResultArray();
        $proposals = [];
        foreach ($contacts as $contact) {
            $replacement = trim((string) ($contact['last_incoming_name'] ?? ''));
            $source = $replacement !== '' ? 'last_incoming_name' : '';
            if ($replacement === '' || mb_strtolower($replacement) === mb_strtolower($suspectName)) {
                [$replacement, $source] = $this->recoverFromMessages((int) $contact['id'], $suspectName);
            }
            if ($replacement === '' || mb_strtolower($replacement) === mb_strtolower($suspectName)) continue;
            $proposals[] = [
                'contact_id'=>(int)$contact['id'], 'instance_id'=>!empty($contact['instance_id'])?(int)$contact['instance_id']:null,
                'phone'=>(string)($contact['phone_normalized']??''), 'current_name'=>(string)$contact['name'],
                'suggested_name'=>mb_substr($replacement, 0, 191), 'source'=>$source,
            ];
        }
        return ['suspect_name'=>$suspectName,'count'=>count($proposals),'proposals'=>$proposals];
    }

    /** @param array<int,int|string> $contactIds */
    public function apply(string $suspectName, array $contactIds, int $actorId): array
    {
        $preview = $this->preview($suspectName, 2000);
        $allowed = array_fill_keys(array_values(array_filter(array_map('intval', $contactIds), static fn(int $id): bool => $id > 0)), true);
        $applied = [];
        foreach ($preview['proposals'] as $proposal) {
            $id = (int) $proposal['contact_id'];
            if (!isset($allowed[$id])) continue;
            $before = $this->db->table('chat_contacts')->where('id', $id)->where('deleted', 0)->get(1)->getRowArray();
            if (!$before || !empty($before['manually_edited']) || mb_strtolower(trim((string)$before['name'])) !== mb_strtolower(trim($suspectName))) continue;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->transStart();
            $this->db->table('chat_contacts')->where('id', $id)->where('deleted', 0)->where('manually_edited', 0)->update([
                'name'=>$proposal['suggested_name'], 'name_source'=>'repair_incoming_history', 'name_updated_at'=>$now, 'updated_at'=>$now,
            ]);
            $this->db->table('chat_conversations')->where('contact_id', $id)->where('deleted', 0)->update(['contact_name'=>$proposal['suggested_name'],'updated_at'=>$now]);
            $this->db->transComplete();
            if (!$this->db->transStatus()) continue;
            (new Audit_service())->record($actorId ?: null, 'contact.name_repaired', 'contact', $id, $proposal['instance_id'], ['name'=>$before['name']], ['name'=>$proposal['suggested_name'],'source'=>$proposal['source']]);
            $applied[] = $proposal;
        }
        return ['requested'=>count($allowed),'applied_count'=>count($applied),'applied'=>$applied];
    }

    /** @return array{0:string,1:string} */
    private function recoverFromMessages(int $contactId, string $suspectName): array
    {
        $messages = $this->db->table('chat_messages m')
            ->select('m.sender_name,m.raw_payload,m.created_at')
            ->join('chat_conversations c', 'c.id=m.conversation_id AND c.deleted=0')
            ->where('c.contact_id', $contactId)->where('m.deleted', 0)->where('m.direction', 'incoming')
            ->where('m.is_group_message', 0)->orderBy('m.message_timestamp', 'DESC')->limit(50)->get()->getResultArray();
        foreach ($messages as $message) {
            $sender = trim((string) ($message['sender_name'] ?? ''));
            if ($this->validCandidate($sender, $suspectName)) return [$sender, 'incoming_sender_name'];
            $raw = json_decode((string) ($message['raw_payload'] ?? ''), true);
            if (!is_array($raw)) continue;
            if ($this->findBoolean($raw, ['fromMe','from_me']) === true) continue;
            foreach (['pushName','push_name','senderName','sender_name','notifyName'] as $key) {
                $value = $this->findScalar($raw, $key);
                if ($this->validCandidate($value, $suspectName)) return [$value, 'incoming_webhook_history'];
            }
        }
        return ['', ''];
    }

    private function validCandidate(string $value, string $suspect): bool
    {
        $value = trim($value);
        return $value !== '' && mb_strlen($value) <= 191 && mb_strtolower($value) !== mb_strtolower(trim($suspect)) && !preg_match('/^[0-9+() .-]+$/', $value);
    }
    private function findScalar(array $data, string $needle): string
    {
        foreach ($data as $key=>$value) {
            if ((string)$key === $needle && is_scalar($value)) return trim((string)$value);
            if (is_array($value)) { $found=$this->findScalar($value,$needle); if ($found!=='') return $found; }
        }
        return '';
    }
    private function findBoolean(array $data, array $needles): ?bool
    {
        foreach ($data as $key=>$value) {
            if (in_array((string)$key,$needles,true) && (is_bool($value)||is_numeric($value)||is_string($value))) return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if (is_array($value)) { $found=$this->findBoolean($value,$needles); if ($found!==null) return $found; }
        }
        return null;
    }
}
