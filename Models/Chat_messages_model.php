<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class Chat_messages_model extends Crud_model
{
    protected $table = null;

    private const WRITABLE_FIELDS = [
        'external_message_id',
        'remote_jid',
        'direction',
        'message_type',
        'text_content',
        'media_url',
        'mime_type',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
        'message_timestamp',
        'dedupe_key',
        'client_message_id',
        'raw_payload',
        'sender_user_id',
        'reply_to_external_message_id',
        'caption',
        'file_name',
        'file_size',
        'media_id',
        'is_internal_note',
        'delivery_error',
        'failed_at',
        'sender_jid',
        'sender_phone',
        'sender_name',
        'sender_contact_id',
        'is_group_message',
        'provider_name',
        'provider_payload_id',
    ];

    public function __construct()
    {
        $this->table = 'chat_messages';
        parent::__construct($this->table);
    }

    public function get_by_id(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Cursor-based history. Results are returned in chronological order even
     * though the database reads the newest matching page first.
     */
    public function get_history(
        int $conversationId,
        int $limit = 50,
        ?int $beforeTimestamp = null,
        ?int $beforeId = null
    ): array {
        if ($conversationId < 1) {
            throw new InvalidArgumentException('Conversation id must be positive.');
        }

        $limit = min(200, max(1, $limit));
        $builder = $this->db->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('message_type !=', 'reaction')
            ->where('deleted', 0);

        if ($beforeTimestamp !== null && $beforeTimestamp > 0) {
            $builder->groupStart()
                ->where('message_timestamp <', $beforeTimestamp);

            if ($beforeId !== null && $beforeId > 0) {
                $builder->orGroupStart()
                    ->where('message_timestamp', $beforeTimestamp)
                    ->where('id <', $beforeId)
                    ->groupEnd();
            }

            $builder->groupEnd();
        } elseif ($beforeId !== null && $beforeId > 0) {
            $builder->where('id <', $beforeId);
        }

        $rows = $builder
            ->orderBy('message_timestamp', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_reverse($rows);
    }

    /**
     * Returns messages inserted after a local cursor in provider chronological
     * order. The local id remains the cursor so polling also sees late-arriving
     * messages whose provider timestamp is older than the last visible item.
     */
    public function get_after(int $conversationId, int $afterId, int $limit = 100): array
    {
        if ($conversationId < 1 || $afterId < 0) {
            throw new InvalidArgumentException('Conversation id and cursor must be valid.');
        }

        $limit = min(200, max(1, $limit));

        $rows = $this->db->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('id >', $afterId)
            ->where('message_type !=', 'reaction')
            ->where('deleted', 0)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        usort($rows, static function (array $left, array $right): int {
            $timestampOrder = (int) ($left['message_timestamp'] ?? 0) <=> (int) ($right['message_timestamp'] ?? 0);

            return $timestampOrder !== 0
                ? $timestampOrder
                : (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });

        return $rows;
    }

    public function upsert_message(int $conversationId, int $instanceId, array $data): int
    {
        if ($conversationId < 1 || $instanceId < 1) {
            throw new InvalidArgumentException('Valid conversation and instance ids are required.');
        }

        $payload = $this->onlyWritable($data);
        foreach (['external_message_id', 'dedupe_key', 'client_message_id'] as $nullableIdentifier) {
            if (array_key_exists($nullableIdentifier, $payload) && trim((string) $payload[$nullableIdentifier]) === '') {
                $payload[$nullableIdentifier] = null;
            }
        }

        if (empty($payload['external_message_id']) && empty($payload['dedupe_key']) && empty($payload['client_message_id'])) {
            throw new InvalidArgumentException('At least one message deduplication identifier is required.');
        }

        foreach (['remote_jid', 'direction'] as $requiredField) {
            if (!isset($payload[$requiredField]) || trim((string) $payload[$requiredField]) === '') {
                throw new InvalidArgumentException("Missing required message field: {$requiredField}.");
            }
        }

        if (isset($payload['raw_payload']) && is_array($payload['raw_payload'])) {
            $payload['raw_payload'] = $this->encodeJson($payload['raw_payload']);
        }

        $payload['conversation_id'] = $conversationId;
        $payload['instance_id'] = $instanceId;
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;

        $success = $this->db->table($this->table)->upsert($payload);
        if ($success === false) {
            throw new RuntimeException('Unable to persist the message.');
        }

        $id = $this->findPersistedId($conversationId, $instanceId, $payload);
        if ($id < 1) {
            throw new RuntimeException('Persisted message could not be found.');
        }

        return $id;
    }

    public function find_by_external_id(int $instanceId, string $externalMessageId): ?array
    {
        $externalMessageId = trim($externalMessageId);
        if ($instanceId < 1 || $externalMessageId === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('external_message_id', $externalMessageId)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function find_by_dedupe_key(int $instanceId, string $dedupeKey): ?array
    {
        $dedupeKey = trim($dedupeKey);
        if ($instanceId < 1 || $dedupeKey === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('dedupe_key', $dedupeKey)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function find_by_client_message_id(int $conversationId, string $clientMessageId): ?array
    {
        $clientMessageId = trim($clientMessageId);
        if ($conversationId < 1 || $clientMessageId === '') {
            return null;
        }

        $row = $this->db->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('client_message_id', $clientMessageId)
            ->where('deleted', 0)
            ->get(1)
            ->getRowArray();

        return $row ?: null;
    }

    public function update_message(int $id, array $data): bool
    {
        if ($id < 1 || !$this->get_by_id($id)) {
            throw new InvalidArgumentException('A valid message id is required.');
        }

        $payload = $this->onlyWritable($data);
        foreach (['external_message_id', 'dedupe_key', 'client_message_id'] as $nullableIdentifier) {
            if (array_key_exists($nullableIdentifier, $payload) && trim((string) $payload[$nullableIdentifier]) === '') {
                $payload[$nullableIdentifier] = null;
            }
        }

        foreach (['remote_jid', 'direction'] as $requiredField) {
            if (array_key_exists($requiredField, $payload) && trim((string) $payload[$requiredField]) === '') {
                throw new InvalidArgumentException("Message field cannot be empty: {$requiredField}.");
            }
        }

        if (isset($payload['raw_payload']) && is_array($payload['raw_payload'])) {
            $payload['raw_payload'] = $this->encodeJson($payload['raw_payload']);
        }

        if ($payload === []) {
            return true;
        }

        $payload['updated_at'] = gmdate('Y-m-d H:i:s');

        return $this->db->table($this->table)
            ->where('id', $id)
            ->where('deleted', 0)
            ->update($payload);
    }

    public function update_status_by_external_id(int $instanceId, string $externalMessageId, string $status): bool
    {
        $externalMessageId = trim($externalMessageId);
        $status = trim($status);
        if ($instanceId < 1 || $externalMessageId === '' || $status === '') {
            throw new InvalidArgumentException('Instance, external message id and status are required.');
        }

        return $this->db->table($this->table)
            ->where('instance_id', $instanceId)
            ->where('external_message_id', $externalMessageId)
            ->where('deleted', 0)
            ->update([
                'status' => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function count_by_conversation(int $conversationId): int
    {
        if ($conversationId < 1) {
            return 0;
        }

        return $this->db->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('message_type !=', 'reaction')
            ->where('deleted', 0)
            ->countAllResults();
    }

    public function count_older(
        int $conversationId,
        ?int $beforeTimestamp = null,
        ?int $beforeId = null
    ): int {
        if ($conversationId < 1) {
            return 0;
        }

        $builder = $this->db->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('message_type !=', 'reaction')
            ->where('deleted', 0);

        if ($beforeTimestamp !== null && $beforeTimestamp > 0) {
            $builder->groupStart()
                ->where('message_timestamp <', $beforeTimestamp);

            if ($beforeId !== null && $beforeId > 0) {
                $builder->orGroupStart()
                    ->where('message_timestamp', $beforeTimestamp)
                    ->where('id <', $beforeId)
                    ->groupEnd();
            }

            $builder->groupEnd();
        } elseif ($beforeId !== null && $beforeId > 0) {
            $builder->where('id <', $beforeId);
        }

        return $builder->countAllResults();
    }

    public static function build_dedupe_key(
        int $instanceId,
        string $remoteJid,
        ?string $externalMessageId,
        int $messageTimestamp
    ): string {
        return hash('sha256', implode('|', [
            (string) $instanceId,
            trim($remoteJid),
            trim((string) $externalMessageId),
            (string) $messageTimestamp,
        ]));
    }

    private function findPersistedId(int $conversationId, int $instanceId, array $payload): int
    {
        $builder = $this->db->table($this->table)->select('id');

        if (!empty($payload['external_message_id'])) {
            $builder->where('instance_id', $instanceId)
                ->where('external_message_id', $payload['external_message_id']);
        } elseif (!empty($payload['dedupe_key'])) {
            $builder->where('instance_id', $instanceId)
                ->where('dedupe_key', $payload['dedupe_key']);
        } else {
            $builder->where('conversation_id', $conversationId)
                ->where('client_message_id', $payload['client_message_id']);
        }

        $row = $builder->get(1)->getRowArray();

        return isset($row['id']) ? (int) $row['id'] : 0;
    }

    private function onlyWritable(array $data): array
    {
        $payload = [];
        foreach (self::WRITABLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        return $payload;
    }

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Message payload could not be encoded as JSON.', 0, $exception);
        }
    }
}
