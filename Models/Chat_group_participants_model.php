<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use InvalidArgumentException;
use RuntimeException;

class Chat_group_participants_model extends Crud_model
{
    protected $table = null;

    private const WRITABLE = [
        'group_id', 'instance_id', 'contact_id', 'participant_jid', 'phone_normalized',
        'display_name', 'role', 'is_self', 'active', 'last_message_at', 'metadata_json',
    ];

    public function __construct()
    {
        $this->table = 'chat_group_participants';
        parent::__construct($this->table);
    }

    public function get_by_group_and_jid(int $groupId, string $jid): ?array
    {
        $jid = trim($jid);
        if ($groupId < 1 || $jid === '') return null;
        $row = $this->db->table($this->table)->where('group_id', $groupId)->where('participant_jid', $jid)->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }

    public function upsert_participant(int $groupId, int $instanceId, string $jid, array $data = []): int
    {
        $jid = trim($jid);
        if ($groupId < 1 || $instanceId < 1 || $jid === '') {
            throw new InvalidArgumentException('Grupo, instancia e participante sao obrigatorios.');
        }
        $payload = [];
        foreach (self::WRITABLE as $field) if (array_key_exists($field, $data)) $payload[$field] = $data[$field];
        if (isset($payload['metadata_json']) && is_array($payload['metadata_json'])) {
            $payload['metadata_json'] = json_encode($payload['metadata_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $payload['group_id'] = $groupId;
        $payload['instance_id'] = $instanceId;
        $payload['participant_jid'] = $jid;
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;
        if ($this->db->table($this->table)->upsert($payload) === false) {
            throw new RuntimeException('Nao foi possivel persistir o participante do grupo.');
        }
        $row = $this->db->table($this->table)->select('id')->where('group_id', $groupId)->where('participant_jid', $jid)->get(1)->getRowArray();
        if (!$row) throw new RuntimeException('Participante persistido nao encontrado.');
        return (int) $row['id'];
    }

    public function list_for_group(int $groupId): array
    {
        if ($groupId < 1) return [];
        return $this->db->table($this->table)->where('group_id', $groupId)->where('deleted', 0)->where('active', 1)->orderBy('display_name', 'ASC')->get()->getResultArray();
    }
}
