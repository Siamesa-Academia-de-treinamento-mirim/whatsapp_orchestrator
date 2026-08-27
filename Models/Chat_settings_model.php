<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Models;

use App\Models\Crud_model;
use Chatwoot_plugin\Libraries\Credential_cipher;
use InvalidArgumentException;

require_once dirname(__DIR__) . '/Libraries/Credential_cipher.php';

class Chat_settings_model extends Crud_model
{
    public const WEBHOOK_SECRET = 'webhook_secret';
    public const POLLING_INTERVAL_MS = 'polling_interval_ms';
    public const EVOLUTION_TIMEOUT_SECONDS = 'evolution_timeout_seconds';
    public const ENDPOINT_CONNECTION_STATE = 'evolution_endpoint_connection_state';
    public const ENDPOINT_FIND_CHATS = 'evolution_endpoint_find_chats';
    public const ENDPOINT_FIND_MESSAGES = 'evolution_endpoint_find_messages';
    public const ENDPOINT_SEND_TEXT = 'evolution_endpoint_send_text';
    public const ENDPOINT_SEND_MEDIA = 'evolution_endpoint_send_media';
    public const ENDPOINT_SEND_REACTION = 'evolution_endpoint_send_reaction';
    public const ENDPOINT_SEND_AUDIO = 'evolution_endpoint_send_audio';
    public const ENDPOINT_MEDIA_BASE64 = 'evolution_endpoint_media_base64';

    protected $table = null;

    private Credential_cipher $credentialCipher;
    /** @var array<string,mixed>|null Values loaded during this request. */
    private ?array $requestCache = null;

    public function __construct(?Credential_cipher $credentialCipher = null)
    {
        $this->table = 'chat_settings';
        parent::__construct($this->table);
        $this->credentialCipher = $credentialCipher ?? new Credential_cipher();
    }

    /**
     * Returns a server-side setting. Encrypted rows are decrypted only here;
     * callers must never serialize a secret into an API response.
     *
     * @return mixed
     */
    public function get_value(string $key, $default = null)
    {
        return $this->get_values([$key], [$key => $default])[$key];
    }

    /**
     * Read several settings with one query. Decryption remains server-side and
     * the cache belongs only to this model/request instance.
     *
     * @param array<int,string> $keys
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    public function get_values(array $keys, array $defaults = []): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '') throw new InvalidArgumentException('Setting key cannot be empty.');
            $normalized[$key] = true;
        }
        $keys = array_keys($normalized);
        if ($this->requestCache === null) $this->requestCache = [];

        $missing = array_values(array_filter($keys, fn (string $key): bool => !array_key_exists($key, $this->requestCache)));
        if ($missing !== []) {
            $rows = $this->db->table($this->table)
                ->whereIn('setting_key', $missing)
                ->where('deleted', 0)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $key = (string) ($row['setting_key'] ?? '');
                if ($key === '') continue;
                $value = $row['setting_value'] ?? null;
                if ((int) ($row['is_encrypted'] ?? 0) === 1 && is_string($value) && $value !== '') {
                    $value = $this->credentialCipher->decrypt($value);
                }
                $this->requestCache[$key] = $value;
            }
            foreach ($missing as $key) {
                if (!array_key_exists($key, $this->requestCache)) $this->requestCache[$key] = null;
            }
        }

        $result = [];
        foreach ($keys as $key) $result[$key] = $this->requestCache[$key] ?? ($defaults[$key] ?? null);
        return $result;
    }

    public function upsert_setting(string $key, ?string $value, bool $encrypted = false): bool
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Setting key cannot be empty.');
        }

        if ($encrypted && ($value === null || $value === '')) {
            throw new InvalidArgumentException('Encrypted settings cannot contain an empty value.');
        }

        $storedValue = $encrypted && $value !== null
            ? $this->credentialCipher->encrypt($value)
            : $value;

        $success = $this->db->table($this->table)->upsert([
            'setting_key' => $key,
            'setting_value' => $storedValue,
            'is_encrypted' => $encrypted ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'deleted' => 0,
        ]);

        if ($success !== false) $this->requestCache = null;

        return $success !== false;
    }

    public function delete_setting(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Setting key cannot be empty.');
        }

        $success = $this->db->table($this->table)
            ->where('setting_key', $key)
            ->update([
                'setting_value' => null,
                'is_encrypted' => 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'deleted' => 1,
            ]);
        if ($success !== false) $this->requestCache = null;
        return $success;
    }

    /**
     * Lists settings without exposing encrypted values.
     */
    public function paginate_settings(int $page = 1, int $perPage = 50): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);

        $total = $this->db->table($this->table)
            ->where('deleted', 0)
            ->countAllResults();

        $rows = $this->db->table($this->table)
            ->select('id, setting_key, setting_value, is_encrypted, created_at, updated_at')
            ->where('deleted', 0)
            ->orderBy('setting_key', 'ASC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['has_value'] = $row['setting_value'] !== null && $row['setting_value'] !== '';
            if ((int) $row['is_encrypted'] === 1) {
                $row['setting_value'] = null;
            }
        }
        unset($row);

        return $this->pageResult($rows, $total, $page, $perPage);
    }

    public function count_active(): int
    {
        return $this->db->table($this->table)
            ->where('deleted', 0)
            ->countAllResults();
    }

    private function pagination(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function pageResult(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }
}
