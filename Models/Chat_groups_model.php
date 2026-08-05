<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use InvalidArgumentException;
use RuntimeException;

class Chat_groups_model extends Crud_model
{
    protected $table = null;

    private const WRITABLE = [
        'instance_id', 'remote_jid', 'subject', 'description', 'owner_jid',
        'profile_picture_url', 'participant_count', 'metadata_json', 'last_synced_at',
    ];

    public function __construct()
    {
        $this->table = 'chat_groups';
        parent::__construct($this->table);
    }

    public function get_by_id(int $id): ?array
    {
        if ($id < 1) return null;
        $row = $this->db->table($this->table)->where('id', $id)->where('deleted', 0)->get(1)->getRowArray();
        return $row ?: null;
    }

    public function get_by_remote_jid(int $instanceId, string $remoteJid): ?array
    {
        $remoteJid = trim($remoteJid);
        if ($instanceId < 1 || $remoteJid === '') return null;
        $row = $this->db->table($this->table)
            ->where('instance_id', $instanceId)->where('remote_jid', $remoteJid)->where('deleted', 0)
            ->get(1)->getRowArray();
        return $row ?: null;
    }

    public function upsert_group(int $instanceId, string $remoteJid, array $data = []): int
    {
        $remoteJid = trim($remoteJid);
        if ($instanceId < 1 || !str_ends_with(strtolower($remoteJid), '@g.us')) {
            throw new InvalidArgumentException('Grupo e instancia validos sao obrigatorios.');
        }
        $payload = [];
        foreach (self::WRITABLE as $field) if (array_key_exists($field, $data)) $payload[$field] = $data[$field];
        if (isset($payload['metadata_json']) && is_array($payload['metadata_json'])) {
            $payload['metadata_json'] = json_encode($payload['metadata_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $payload['instance_id'] = $instanceId;
        $payload['remote_jid'] = $remoteJid;
        $payload['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload['deleted'] = 0;
        if ($this->db->table($this->table)->upsert($payload) === false) {
            throw new RuntimeException('Nao foi possivel persistir o grupo.');
        }
        $row = $this->db->table($this->table)->select('id')->where('instance_id', $instanceId)->where('remote_jid', $remoteJid)->get(1)->getRowArray();
        if (!$row) throw new RuntimeException('Grupo persistido nao encontrado.');
        return (int) $row['id'];
    }
}
