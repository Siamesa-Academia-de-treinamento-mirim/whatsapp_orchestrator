<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_contact_identifiers_model;
use Chatwoot_plugin\Models\Chat_contacts_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_tags_model;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Contact_service
{
    private BaseConnection $db;

    public function __construct(
        private ?Chat_contacts_model $contacts = null,
        private ?Chat_contact_identifiers_model $identifiers = null,
        private ?Chat_tags_model $tags = null,
        private ?Chat_instances_model $instances = null,
        private ?Audit_service $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->contacts ??= new Chat_contacts_model();
        $this->identifiers ??= new Chat_contact_identifiers_model();
        $this->tags ??= new Chat_tags_model();
        $this->instances ??= new Chat_instances_model();
        $this->audit ??= new Audit_service();
        $this->db = $db ?? db_connect('default');
    }

    public function normalize_phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        if (strlen($digits) === 10 || strlen($digits) === 11) $digits = '55' . $digits;
        if (strlen($digits) < 12 || strlen($digits) > 15 || $digits[0] === '0') {
            throw new InvalidArgumentException('Informe um telefone valido em formato internacional.');
        }
        return $digits;
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list(array $filters = [], int $page = 1, int $limit = 30): array
    {
        $result = $this->contacts->search($filters, $page, $limit);
        $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $result['data'])));
        $counts = [];
        $tagMap = [];
        $instanceMap = [];
        if ($ids) {
            $countRows = $this->db->table('chat_conversations')->select('contact_id, COUNT(id) total', false)->whereIn('contact_id', $ids)->where('deleted', 0)->groupBy('contact_id')->get()->getResultArray();
            foreach ($countRows as $row) $counts[(int) $row['contact_id']] = (int) $row['total'];
            $contactTagsTable = $this->db->prefixTable('chat_contact_tags');
            $tagsTable = $this->db->prefixTable('chat_tags');
            $tagRows = $this->db->table($contactTagsTable)
                ->select($contactTagsTable . '.contact_id, ' . $tagsTable . '.name')
                ->join($tagsTable, $tagsTable . '.id=' . $contactTagsTable . '.tag_id AND ' . $tagsTable . '.deleted=0')
                ->whereIn($contactTagsTable . '.contact_id', $ids)
                ->where($contactTagsTable . '.deleted', 0)
                ->orderBy($tagsTable . '.name', 'ASC')->get()->getResultArray();
            foreach ($tagRows as $row) $tagMap[(int) $row['contact_id']][] = (string) $row['name'];
            $instanceIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['instance_id'] ?? 0), $result['data']))));
            if ($instanceIds) {
                $instanceRows = $this->db->table('chat_instances')->select('id,name')->whereIn('id', $instanceIds)->where('deleted', 0)->get()->getResultArray();
                foreach ($instanceRows as $row) $instanceMap[(int) $row['id']] = (string) $row['name'];
            }
        }
        $result['data'] = array_map(function (array $row) use ($counts, $tagMap, $instanceMap): array {
            $mapped = $this->map($row, $counts[(int) $row['id']] ?? 0, $tagMap[(int) $row['id']] ?? []);
            $mapped['instance'] = $instanceMap[(int) ($row['instance_id'] ?? 0)] ?? '—';
            return $mapped;
        }, $result['data']);
        return $result;
    }

    /** @return array{total:int,with_conversation:int,unidentified:int,opt_out:int} */
    public function summary(): array
    {
        $contactsTable = $this->db->prefixTable('chat_contacts');
        $conversationsTable = $this->db->prefixTable('chat_conversations');
        $base = fn () => $this->db->table($contactsTable)->where($contactsTable . '.deleted', 0);

        $total = $base()->countAllResults();
        $withConversationRow = $base()
            ->select('COUNT(DISTINCT ' . $contactsTable . '.id) total', false)
            ->join($conversationsTable, $conversationsTable . '.contact_id=' . $contactsTable . '.id AND ' . $conversationsTable . '.deleted=0')
            ->get()->getRowArray();
        $unidentified = $base()->groupStart()
            ->where($contactsTable . '.name IS NULL', null, false)
            ->orWhere('TRIM(' . $contactsTable . ".name) = ''", null, false)
            ->orWhere($contactsTable . '.name = ' . $contactsTable . '.phone_normalized', null, false)
            ->orWhere('LOWER(' . $contactsTable . '.name)', 'contato')
            ->groupEnd()->countAllResults();
        $optOut = $base()->where($contactsTable . '.opt_out', 1)->countAllResults();

        return [
            'total' => $total,
            'with_conversation' => (int) ($withConversationRow['total'] ?? 0),
            'unidentified' => $unidentified,
            'opt_out' => $optOut,
        ];
    }

    public function get(int $id): ?array
    {
        $row = $this->contacts->get_by_id($id);
        if (!$row) return null;
        $mapped = $this->map($row);
        $instance = !empty($row['instance_id']) ? $this->instances->get_by_id((int) $row['instance_id']) : null;
        $mapped['instance'] = (string) ($instance['name'] ?? '—');
        return $mapped;
    }

    public function save(array $input, int $actorId, ?int $id = null, bool $automatic = false): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) throw new InvalidArgumentException('Nome do contato invalido.');
        $phone = $this->normalize_phone((string) ($input['phone'] ?? $input['phone_number'] ?? ''));
        $instanceId = !empty($input['instance_id']) ? (int) $input['instance_id'] : null;
        if ($instanceId !== null && !$this->instances->get_by_id($instanceId)) throw new InvalidArgumentException('Instancia preferencial invalida.');
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191)) throw new InvalidArgumentException('E-mail invalido.');

        $scopeKey = hash('sha256', ($instanceId ?: 0) . '|' . $phone);
        $scopeContact = $this->contacts->find_by_scope($scopeKey);
        $existing = $id ? $this->contacts->get_by_id($id) : $scopeContact;
        if ($id && !$existing) throw new RuntimeException('Contato nao encontrado.', 404);
        if ($id && $scopeContact && (int) $scopeContact['id'] !== $id) {
            throw new RuntimeException('Ja existe outro contato com este telefone no mesmo escopo.', 409);
        }
        if (!$id && !$automatic && $scopeContact) {
            $existingEmail = mb_strtolower(trim((string) ($scopeContact['email'] ?? '')));
            $nextEmail = mb_strtolower($email);
            if ($existingEmail !== '' && $nextEmail !== '' && $existingEmail !== $nextEmail) {
                throw new RuntimeException('O telefone informado pertence a um contato com outro e-mail. Revise o cadastro antes de mesclar.', 409);
            }
        }
        $before = $existing ?: [];
        $payload = [
            'instance_id' => $instanceId,
            'name' => mb_substr($name, 0, 191),
            'name_source' => $automatic ? (string) ($input['name_source'] ?? ($existing['name_source'] ?? 'automatic')) : 'manual',
            'name_updated_at' => gmdate('Y-m-d H:i:s'),
            'phone_normalized' => $phone,
            'email' => $email ?: null,
            'company' => $this->nullableText($input['company'] ?? null, 191),
            'city' => $this->nullableText($input['city'] ?? null, 191),
            'source' => $this->source((string) ($input['source'] ?? 'whatsapp')),
            'notes' => $this->nullableText($input['notes'] ?? null, 10000),
            'opt_out' => !empty($input['opt_out']) ? 1 : 0,
            'manually_edited' => $automatic ? (int) ($existing['manually_edited'] ?? 0) : 1,
            'scope_key' => $scopeKey,
            'last_activity_at' => $input['last_activity_at'] ?? ($existing['last_activity_at'] ?? null),
        ];
        if ($automatic && $existing) {
            $allowNameUpdate = !empty($input['allow_name_update']) && empty($existing['manually_edited']);
            if (!$allowNameUpdate) {
                unset($payload['name'], $payload['name_source'], $payload['name_updated_at']);
            }
            if (!empty($existing['manually_edited'])) {
                foreach (['email', 'company', 'city', 'notes', 'opt_out'] as $field) unset($payload[$field]);
            }
        }

        $this->db->transStart();
        if ($existing) {
            $id = (int) $existing['id'];
            $this->contacts->update_record($id, $payload);
        } else {
            $id = $this->contacts->create_record($payload);
        }
        $this->identifiers->add_identifier($id, $instanceId, 'phone', $phone, true);
        if (isset($input['tags']) && is_array($input['tags'])) $this->setTags($id, $input['tags']);
        $this->db->transComplete();
        if (!$this->db->transStatus()) throw new RuntimeException('Nao foi possivel salvar o contato.');

        $saved = $this->get($id);
        $this->audit->record($actorId, $existing ? 'contact.updated' : 'contact.created', 'contact', $id, $instanceId, $before, $saved ?: []);
        return $saved ?: [];
    }

    public function resolve_for_message(int $instanceId, array $normalized, int $conversationId = 0): array
    {
        // A group is the conversation identity, while each author is handled by
        // Group_service. Creating a contact for the @g.us JID corrupts contacts.
        if (!empty($normalized['is_group']) || str_ends_with(strtolower(trim((string) ($normalized['remote_jid'] ?? ''))), '@g.us')) {
            return [];
        }

        $remoteJid = trim((string) ($normalized['remote_jid'] ?? ''));
        $alternateJid = trim((string) ($normalized['alternate_remote_jid'] ?? ''));
        $phone = trim((string) ($normalized['phone_number'] ?? ''));
        if ($phone === '') {
            foreach ([$alternateJid, $remoteJid] as $jid) {
                if (str_ends_with(strtolower($jid), '@s.whatsapp.net')) {
                    $phone = strstr($jid, '@', true) ?: '';
                    break;
                }
            }
        }
        if ($phone === '') throw new InvalidArgumentException('Mensagem sem telefone resolvivel para contato.');
        $phone = $this->normalize_phone($phone);

        $contact = $this->findByIdentifiers($instanceId, $remoteJid, $alternateJid, $phone);

        // fromMe pushName belongs to the connected account. Never use it to
        // create or rename the customer. Existing contact linkage is enough.
        if (!empty($normalized['from_me'])) {
            if ($contact && $conversationId > 0) {
                $this->db->table('chat_conversations')->where('id', $conversationId)->update([
                    'contact_id' => (int) $contact['id'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return $contact ? $this->get((int) $contact['id']) ?? $contact : [];
        }

        $incomingName = trim((string) ($normalized['contact_name'] ?? $normalized['sender_name'] ?? ''));
        $name = $incomingName !== '' ? $incomingName : (string) ($contact['name'] ?? $phone);
        $saved = $this->save([
            'name' => $name,
            'phone' => $phone,
            'instance_id' => $contact['instance_id'] ?? $instanceId,
            'source' => 'whatsapp',
            'name_source' => 'incoming_push_name',
            'allow_name_update' => $incomingName !== '',
            'last_activity_at' => gmdate('Y-m-d H:i:s', (int) ($normalized['timestamp'] ?? time())),
            'opt_out' => (int) ($contact['opt_out'] ?? 0),
        ], 0, $contact ? (int) $contact['id'] : null, true);
        $contactId = (int) $saved['id'];
        foreach ([['jid', $remoteJid], ['jid', $alternateJid], ['phone', $phone]] as [$type, $value]) {
            if ($value !== '') $this->identifiers->add_identifier($contactId, $instanceId, $type, $value, $type === 'phone');
        }
        if ($incomingName !== '') {
            $this->db->table('chat_contacts')->where('id', $contactId)->update([
                'last_incoming_name' => mb_substr($incomingName, 0, 191),
                'last_incoming_name_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        if ($conversationId > 0) {
            $this->db->table('chat_conversations')->where('id', $conversationId)->update(['contact_id' => $contactId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
        return $saved;
    }

    /** Creates or resolves a real person who authored a group message. */
    public function resolve_for_participant(int $instanceId, array $normalized): array
    {
        $jid = trim((string) ($normalized['participant_jid'] ?? $normalized['sender_jid'] ?? ''));
        $phone = trim((string) ($normalized['sender_phone'] ?? ''));
        if ($phone === '' && str_ends_with(strtolower($jid), '@s.whatsapp.net')) {
            $phone = strstr($jid, '@', true) ?: '';
        }
        if ($phone === '') throw new InvalidArgumentException('Participante sem telefone resolvivel.');
        $phone = $this->normalize_phone($phone);
        $contact = $this->findByIdentifiers($instanceId, $jid, '', $phone);
        $incomingName = trim((string) ($normalized['sender_name'] ?? ''));
        $name = $incomingName !== '' ? $incomingName : (string) ($contact['name'] ?? $phone);
        $saved = $this->save([
            'name' => $name,
            'phone' => $phone,
            'instance_id' => $contact['instance_id'] ?? $instanceId,
            'source' => 'whatsapp_group',
            'name_source' => 'group_participant',
            'allow_name_update' => $incomingName !== '',
            'last_activity_at' => gmdate('Y-m-d H:i:s', (int) ($normalized['timestamp'] ?? time())),
            'opt_out' => (int) ($contact['opt_out'] ?? 0),
        ], 0, $contact ? (int) $contact['id'] : null, true);
        $contactId = (int) $saved['id'];
        foreach ([['jid', $jid], ['phone', $phone]] as [$type, $value]) {
            if ($value !== '') $this->identifiers->add_identifier($contactId, $instanceId, $type, $value, $type === 'phone');
        }
        return $saved;
    }

    private function findByIdentifiers(int $instanceId, string $remoteJid, string $alternateJid, string $phone): ?array
    {
        foreach ([['jid', $remoteJid], ['jid', $alternateJid], ['phone', $phone]] as [$type, $value]) {
            if ($value === '') continue;
            $identifier = $this->identifiers->find_identifier($instanceId, $type, $value);
            if ($identifier) return $this->contacts->get_by_id((int) $identifier['contact_id']);
        }
        return $this->contacts->find_by_phone($phone, $instanceId);
    }

    public function delete(int $id, int $actorId): void
    {
        $before = $this->get($id);
        if (!$before) throw new RuntimeException('Contato nao encontrado.', 404);
        $this->contacts->soft_delete($id);
        $this->audit->record($actorId, 'contact.deleted', 'contact', $id, isset($before['instance_id']) ? (int) $before['instance_id'] : null, $before);
    }

    public function set_opt_out(int $id, bool $value, int $actorId): array
    {
        $before = $this->get($id);
        if (!$before) throw new RuntimeException('Contato nao encontrado.', 404);
        $this->contacts->update_record($id, ['opt_out' => $value ? 1 : 0, 'manually_edited' => 1]);
        $saved = $this->get($id) ?: [];
        $this->audit->record($actorId, 'contact.opt_out', 'contact', $id, isset($before['instance_id']) ? (int) $before['instance_id'] : null, $before, $saved);
        return $saved;
    }

    public function bulk_tags(array $ids, array $tags, int $actorId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!$ids || count($ids) > 500) throw new InvalidArgumentException('Selecione entre 1 e 500 contatos.');
        foreach ($ids as $id) {
            if ($this->contacts->get_by_id($id)) $this->setTags($id, $tags);
        }
        $this->audit->record($actorId, 'contact.bulk_tags', 'contact', implode(',', $ids), null, [], ['tags' => $this->normalizeTags($tags)]);
        return count($ids);
    }

    public function import_csv(string $path, int $actorId, bool $dryRun = false): array
    {
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) throw new InvalidArgumentException('CSV ausente ou maior que 5 MB.');
        $sample = (string) file_get_contents($path, false, null, 0, 8192);
        if (str_contains($sample, "\0")) throw new InvalidArgumentException('Arquivo CSV invalido.');
        if (!mb_check_encoding($sample, 'UTF-8')) $sample = mb_convert_encoding($sample, 'UTF-8', 'Windows-1252');
        $firstLine = preg_split('/\r\n|\r|\n/', $sample, 2)[0] ?? '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $handle = fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('Nao foi possivel ler o CSV.');
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) { fclose($handle); throw new InvalidArgumentException('CSV sem cabecalho.'); }
        $headers = array_map(fn ($value): string => $this->header((string) $value), $headers);
        $result = ['inserted' => 0, 'updated' => 0, 'ignored' => 0, 'errors' => []];
        $line = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($line > 5001) { $result['errors'][] = ['line' => $line, 'message' => 'Limite de 5000 registros excedido.']; break; }
            $values = array_pad($row, count($headers), '');
            $item = array_combine($headers, array_slice($values, 0, count($headers))) ?: [];
            $phoneValue = (string) ($item['phone'] ?? '');
            $nameValue = trim((string) ($item['name'] ?? ''));
            if ($phoneValue === '' || $nameValue === '') { $result['ignored']++; continue; }
            try {
                $phone = $this->normalize_phone($phoneValue);
                $existing = $this->contacts->find_by_phone($phone, null);
                if (!$dryRun) {
                    $this->save(['name' => $nameValue, 'phone' => $phone, 'email' => $item['email'] ?? '', 'company' => $item['company'] ?? '', 'city' => $item['city'] ?? '', 'source' => 'import', 'tags' => isset($item['tags']) ? preg_split('/[,|]/', (string) $item['tags']) : [], 'opt_out' => $existing['opt_out'] ?? 0], $actorId, $existing ? (int) $existing['id'] : null);
                }
                $result[$existing ? 'updated' : 'inserted']++;
            } catch (Throwable $exception) {
                $result['errors'][] = ['line' => $line, 'message' => $exception->getMessage()];
            }
        }
        fclose($handle);
        $this->audit->record($actorId, $dryRun ? 'contact.import_preview' : 'contact.import', 'contact_import', null, null, [], array_diff_key($result, ['errors' => true]));
        return $result;
    }

    public function export_rows(array $filters = []): array
    {
        $result = $this->list($filters, 1, 10000);
        return $result['data'];
    }

    private function setTags(int $contactId, array $names): void
    {
        $names = $this->normalizeTags($names);
        $table = 'chat_contact_tags';
        $this->db->table($table)->where('contact_id', $contactId)->update(['deleted' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        foreach ($names as $name) {
            $tagId = $this->tags->resolve($name);
            $existing = $this->db->table($table)->where('contact_id', $contactId)->where('tag_id', $tagId)->get(1)->getRowArray();
            $data = ['contact_id' => $contactId, 'tag_id' => $tagId, 'deleted' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($existing) $this->db->table($table)->where('id', (int) $existing['id'])->update($data);
            else { $data['created_at'] = gmdate('Y-m-d H:i:s'); $this->db->table($table)->insert($data); }
        }
    }

    private function map(array $row, ?int $conversationCount = null, ?array $tagNames = null): array
    {
        $id = (int) $row['id'];
        $conversationCount ??= $this->db->table('chat_conversations')->where('contact_id', $id)->where('deleted', 0)->countAllResults();
        $tagNames ??= $this->tags->names_for('chat_contact_tags', 'contact_id', $id);
        return [
            'id' => $id,
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone_normalized'],
            'email' => (string) ($row['email'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'source' => (string) ($row['source'] ?? 'whatsapp'),
            'instance_id' => isset($row['instance_id']) ? (int) $row['instance_id'] : null,
            'tags' => $tagNames,
            'notes' => (string) ($row['notes'] ?? ''),
            'opt_out' => (int) ($row['opt_out'] ?? 0) === 1,
            'last_activity_at' => $this->iso($row['last_activity_at'] ?? null),
            'conversation_count' => $conversationCount,
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function normalizeTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            $name = trim((string) $tag);
            if ($name !== '' && mb_strlen($name) <= 100) $result[mb_strtolower($name)] = $name;
        }
        return array_values(array_slice($result, 0, 50));
    }

    private function nullableText($value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function source(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['whatsapp', 'campaign', 'manual', 'meta', 'import', 'other'], true) ? $source : 'other';
    }

    private function header(string $value): string
    {
        $value = mb_strtolower(trim($value, "\xEF\xBB\xBF \t\r\n"));
        $map = ['nome' => 'name', 'name' => 'name', 'telefone' => 'phone', 'phone' => 'phone', 'celular' => 'phone', 'email' => 'email', 'e-mail' => 'email', 'empresa' => 'company', 'company' => 'company', 'cidade' => 'city', 'city' => 'city', 'tags' => 'tags', 'etiquetas' => 'tags'];
        return $map[$value] ?? preg_replace('/[^a-z0-9_]+/', '_', $value);
    }

    private function iso($value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
