<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Database\Migrations;

use CodeIgniter\Database\Migration;

class V003_Backfill_conversation_contacts extends Migration
{
    public const VERSION = 3;

    public function up(): void
    {
        $this->seedSetting('n8n_header_name', 'X-API-Key');
        $this->seedSetting('n8n_allow_private_networks', '0');
        $this->backfillContacts();
    }

    public function down(): void
    {
        // Contact links are useful operational data and are not removed.
    }

    private function backfillContacts(): void
    {
        $conversationsTable = $this->db->prefixTable('chat_conversations');
        $contactsTable = $this->db->prefixTable('chat_contacts');
        $identifiersTable = $this->db->prefixTable('chat_contact_identifiers');

        $lastId = 0;
        do {
            $rows = $this->db->table($conversationsTable)
                ->select('id,instance_id,remote_jid,phone_number,contact_name,last_message_at,created_at')
                ->where('deleted', 0)
                ->where('contact_id IS NULL', null, false)
                ->where('id >', $lastId)
                ->orderBy('id', 'ASC')
                ->limit(500)
                ->get()->getResultArray();

            foreach ($rows as $conversation) {
                $lastId = (int) $conversation['id'];
                $phone = $this->phone($conversation);
                if ($phone === '') {
                    // Groups and unresolved LIDs do not represent a unique phone contact.
                    continue;
                }

                $instanceId = (int) $conversation['instance_id'];
                $scopeKey = hash('sha256', $instanceId . '|' . $phone);
                $contact = $this->db->table($contactsTable)->where('scope_key', $scopeKey)->where('deleted', 0)->get(1)->getRowArray();
                $name = $this->name((string) ($conversation['contact_name'] ?? ''), $phone);
                $activity = $conversation['last_message_at'] ?: $conversation['created_at'] ?: gmdate('Y-m-d H:i:s');
                $now = gmdate('Y-m-d H:i:s');

                if (!$contact) {
                    $this->db->table($contactsTable)->insert([
                        'instance_id' => $instanceId,
                        'name' => $name,
                        'phone_normalized' => $phone,
                        'source' => 'whatsapp',
                        'opt_out' => 0,
                        'manually_edited' => 0,
                        'scope_key' => $scopeKey,
                        'last_activity_at' => $activity,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted' => 0,
                    ]);
                    $contactId = (int) $this->db->insertID();
                } else {
                    $contactId = (int) $contact['id'];
                    $updates = ['updated_at' => $now];
                    $currentActivity = strtotime((string) ($contact['last_activity_at'] ?? '')) ?: 0;
                    if ((strtotime((string) $activity) ?: 0) > $currentActivity) $updates['last_activity_at'] = $activity;
                    if (empty($contact['manually_edited']) && $this->isPlaceholder((string) ($contact['name'] ?? ''), $phone) && !$this->isPlaceholder($name, $phone)) {
                        $updates['name'] = $name;
                    }
                    $this->db->table($contactsTable)->where('id', $contactId)->update($updates);
                }

                $this->identifier($identifiersTable, $contactId, $instanceId, 'phone', $phone, true);
                $jid = trim((string) ($conversation['remote_jid'] ?? ''));
                if ($jid !== '' && strlen($jid) <= 191) $this->identifier($identifiersTable, $contactId, $instanceId, 'jid', $jid, false);
                $this->db->table($conversationsTable)->where('id', (int) $conversation['id'])->update(['contact_id' => $contactId, 'updated_at' => $now]);
            }
        } while ($rows !== []);
    }

    private function phone(array $conversation): string
    {
        $candidates = [(string) ($conversation['phone_number'] ?? '')];
        $jid = trim((string) ($conversation['remote_jid'] ?? ''));
        if (str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us')) {
            $candidates[] = strstr($jid, '@', true) ?: '';
        }
        foreach ($candidates as $candidate) {
            $digits = preg_replace('/\D+/', '', $candidate) ?: '';
            if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
            if (strlen($digits) === 10 || strlen($digits) === 11) $digits = '55' . $digits;
            if (strlen($digits) >= 12 && strlen($digits) <= 15 && $digits[0] !== '0') return $digits;
        }
        return '';
    }

    private function name(string $name, string $phone): string
    {
        $name = trim($name);
        return $name === '' ? $phone : mb_substr($name, 0, 191);
    }

    private function isPlaceholder(string $name, string $phone): bool
    {
        $name = trim($name);
        return $name === '' || $name === $phone || mb_strtolower($name) === 'contato';
    }

    private function identifier(string $table, int $contactId, int $instanceId, string $type, string $value, bool $primary): void
    {
        $existing = $this->db->table($table)->select('id')->where('instance_id', $instanceId)->where('identifier_type', $type)->where('identifier_value', $value)->get(1)->getRowArray();
        $data = ['contact_id' => $contactId, 'is_primary' => $primary ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s'), 'deleted' => 0];
        if ($existing) {
            $this->db->table($table)->where('id', (int) $existing['id'])->update($data);
            return;
        }
        $this->db->table($table)->insert(array_replace($data, [
            'instance_id' => $instanceId,
            'identifier_type' => $type,
            'identifier_value' => $value,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]));
    }

    private function seedSetting(string $key, string $value): void
    {
        $table = $this->db->prefixTable('chat_settings');
        if ($this->db->table($table)->where('setting_key', $key)->countAllResults() > 0) return;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($table)->insert(['setting_key' => $key, 'setting_value' => $value, 'is_encrypted' => 0, 'created_at' => $now, 'updated_at' => $now, 'deleted' => 0]);
    }
}
