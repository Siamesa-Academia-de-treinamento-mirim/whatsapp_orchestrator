<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Libraries\Evolution_client;
use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Models\Chat_media_model;
use Chatwoot_plugin\Models\Chat_messages_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

class Media_service
{
    private const MIME_TYPES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'audio/ogg' => ['audio', 'ogg'],
        'audio/mpeg' => ['audio', 'mp3'],
        'audio/mp4' => ['audio', 'm4a'],
        'audio/x-m4a' => ['audio', 'm4a'],
        'audio/wav' => ['audio', 'wav'],
        'audio/x-wav' => ['audio', 'wav'],
        'audio/webm' => ['audio', 'webm'],
        'video/mp4' => ['video', 'mp4'],
        'application/pdf' => ['document', 'pdf'],
        'text/plain' => ['document', 'txt'],
        'application/msword' => ['document', 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        'application/vnd.ms-excel' => ['document', 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 'xlsx'],
    ];

    public function __construct(
        private ?Chat_media_model $media = null,
        private ?Chat_messages_model $messages = null,
        private ?Chat_conversations_model $conversations = null,
        private ?Chat_instances_model $instances = null,
        private ?Chat_settings_model $settings = null,
        private ?Audit_service $audit = null
    ) {
        $this->media ??= new Chat_media_model();
        $this->messages ??= new Chat_messages_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->audit ??= new Audit_service();
    }

    public function send(int $conversationId, UploadedFile $file, string $caption, string $clientMessageId, int $actorId): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) {
            throw new RuntimeException('Conversa nao encontrada.', 404);
        }
        $instance = $this->instances->get_by_id((int) $conversation['instance_id']);
        if (!$instance || empty($instance['active']) || (string) ($instance['connection_status'] ?? '') !== 'connected') {
            throw new RuntimeException('A instancia esta desconectada; o envio foi bloqueado.', 409);
        }
        if (!$file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Arquivo de anexo invalido.');
        }
        $maxBytes = min(64, max(1, (int) $this->settings->get_value('media_max_upload_mb', 16))) * 1024 * 1024;
        $size = (int) $file->getSize();
        if ($size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException('O anexo excede o limite configurado de ' . (int) ($maxBytes / 1024 / 1024) . ' MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName()) ?: '';
        if (!isset(self::MIME_TYPES[$mime])) {
            throw new InvalidArgumentException('Tipo de arquivo nao permitido: ' . ($mime ?: 'desconhecido') . '.');
        }
        [$mediaType, $extension] = self::MIME_TYPES[$mime];
        $caption = mb_substr(trim($caption), 0, 4096);
        $clientMessageId = trim($clientMessageId) ?: 'media-' . bin2hex(random_bytes(12));
        if (!preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $clientMessageId)) {
            throw new InvalidArgumentException('Identificador idempotente do anexo invalido.');
        }
        $existing = $this->messages->find_by_client_message_id($conversationId, $clientMessageId);
        if ($existing && in_array((string) ($existing['status'] ?? ''), ['sent', 'delivered', 'read'], true)) {
            return $this->projectMessage($existing);
        }

        $original = $this->safeName($file->getClientName(), $extension);
        $sha = hash_file('sha256', $file->getTempName());
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Nao foi possivel preparar o armazenamento de midia.');
        }
        $storedName = $sha . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $file->move($directory, $storedName, true);
        $relativePath = $relativeDirectory . '/' . $storedName;
        $mediaId = $this->media->create_record([
            'conversation_id' => $conversationId,
            'instance_id' => (int) $instance['id'],
            'storage_driver' => 'local',
            'storage_path' => $relativePath,
            'original_name' => $original,
            'mime_type' => $mime,
            'media_type' => $mediaType,
            'file_size' => $size,
            'sha256' => $sha,
            'created_by' => $actorId,
            'expires_at' => null,
        ]);
        $now = time();
        $messageId = $existing ? (int) $existing['id'] : $this->messages->upsert_message($conversationId, (int) $instance['id'], [
            'remote_jid' => (string) $conversation['remote_jid'],
            'direction' => 'outgoing',
            'message_type' => $mediaType,
            'text_content' => $caption,
            'caption' => $caption,
            'mime_type' => $mime,
            'file_name' => $original,
            'file_size' => $size,
            'media_id' => $mediaId,
            'media_url' => $this->mediaUrl($mediaId),
            'status' => 'sending',
            'sent_at' => gmdate('Y-m-d H:i:s', $now),
            'message_timestamp' => $now,
            'client_message_id' => $clientMessageId,
            'dedupe_key' => hash('sha256', $instance['id'] . '|' . $conversation['remote_jid'] . '|media|' . $clientMessageId),
            'sender_user_id' => $actorId,
            'raw_payload' => ['source' => 'rise_media_upload', 'sha256' => $sha],
        ]);
        if ($existing) {
            $this->messages->update_message($messageId, ['media_id' => $mediaId, 'media_url' => $this->mediaUrl($mediaId), 'status' => 'sending']);
        }
        $this->media->update_record($mediaId, ['message_id' => $messageId]);

        $number = (string) ($conversation['phone_number'] ?: $conversation['remote_jid']);
        if (str_ends_with((string) $conversation['remote_jid'], '@g.us')) {
            $number = (string) $conversation['remote_jid'];
        }
        if (str_ends_with((string) $conversation['remote_jid'], '@lid')) {
            $lid = preg_replace('/\D+/', '', strstr((string) $conversation['remote_jid'], '@', true) ?: '');
            $number = preg_replace('/\D+/', '', $number) ?: '';
            if ($number === '' || $number === $lid) {
                $this->messages->update_message($messageId, ['status' => 'failed', 'delivery_error' => 'Numero real @lid nao resolvido.', 'failed_at' => gmdate('Y-m-d H:i:s')]);
                throw new RuntimeException('O numero real deste contato @lid ainda nao foi resolvido.', 409);
            }
        }
        $instance['api_key'] = $this->instances->get_decrypted_api_key((int) $instance['id']) ?: '';
        $client = new Evolution_client(['instance' => $instance, 'timeout' => (int) $this->settings->get_value(Chat_settings_model::EVOLUTION_TIMEOUT_SECONDS, 30)], null, $this->settings);
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;
        $base64 = base64_encode((string) file_get_contents($path));
        $response = $client->send_media($number, $base64, $mime, $mediaType, $original, $caption);
        if (empty($response['success'])) {
            $error = mb_substr((string) ($response['error'] ?? 'A Evolution API nao confirmou o envio.'), 0, 1000);
            $this->messages->update_message($messageId, ['status' => 'failed', 'delivery_error' => $error, 'failed_at' => gmdate('Y-m-d H:i:s')]);
            $this->audit->record($actorId, 'message.media_failed', 'message', $messageId, (int) $instance['id'], [], ['media_id' => $mediaId, 'error' => $error]);
            throw new RuntimeException($error, 502);
        }
        $externalId = trim((string) ($response['message_id'] ?? '')) ?: null;
        $this->messages->update_message($messageId, ['external_message_id' => $externalId, 'status' => 'sent', 'delivery_error' => null, 'failed_at' => null]);
        $preview = $caption !== '' ? $caption : '[' . $mediaType . '] ' . $original;
        $this->conversations->upsert_conversation((int) $instance['id'], (string) $conversation['remote_jid'], ['last_message_preview' => $preview, 'last_message_at' => gmdate('Y-m-d H:i:s', $now), 'last_human_message_at' => gmdate('Y-m-d H:i:s', $now)]);
        $saved = $this->messages->get_by_id($messageId) ?: [];
        $this->audit->record($actorId, 'message.media_sent', 'message', $messageId, (int) $instance['id'], [], ['media_id' => $mediaId, 'message_type' => $mediaType]);
        return $this->projectMessage($saved);
    }

    public function upload(UploadedFile $file, int $actorId, ?int $instanceId = null): array
    {
        if (!$file->isValid() || $file->hasMoved()) throw new InvalidArgumentException('Arquivo de midia invalido.');
        if ($instanceId && !$this->instances->get_by_id($instanceId)) throw new InvalidArgumentException('Instancia da midia invalida.');
        $maxBytes = min(64, max(1, (int) $this->settings->get_value('media_max_upload_mb', 16))) * 1024 * 1024;
        $size = (int) $file->getSize();
        if ($size < 1 || $size > $maxBytes) throw new InvalidArgumentException('O arquivo excede o limite configurado.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName()) ?: '';
        if (!isset(self::MIME_TYPES[$mime])) throw new InvalidArgumentException('Tipo de arquivo nao permitido.');
        [$mediaType, $extension] = self::MIME_TYPES[$mime];
        $sha = hash_file('sha256', $file->getTempName());
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Nao foi possivel preparar o armazenamento de midia.');
        $storedName = $sha . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $file->move($directory, $storedName, true);
        $id = $this->media->create_record(['instance_id'=>$instanceId,'storage_driver'=>'local','storage_path'=>$relativeDirectory.'/'.$storedName,'original_name'=>$this->safeName($file->getClientName(),$extension),'mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size,'sha256'=>$sha,'created_by'=>$actorId]);
        $this->audit->record($actorId, 'media.uploaded', 'media', $id, $instanceId, [], ['mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size]);
        return ['id'=>$id,'media_id'=>$id,'url'=>$this->mediaUrl($id),'name'=>$this->safeName($file->getClientName(),$extension),'mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size];
    }

    /** @return array{body:string,mime:string,name:string} */
    public function content(int $mediaId): array
    {
        $row = $this->media->get_by_id($mediaId);
        if (!$row) {
            throw new RuntimeException('Midia nao encontrada.', 404);
        }
        if ((string) ($row['storage_driver'] ?? '') !== 'local') {
            throw new RuntimeException('Driver de midia nao suportado.', 422);
        }
        $root = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads');
        $path = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['storage_path']));
        if (!$root || !$path || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path)) {
            throw new RuntimeException('Arquivo de midia indisponivel.', 404);
        }
        return ['body' => (string) file_get_contents($path), 'mime' => (string) $row['mime_type'], 'name' => $this->safeName((string) ($row['original_name'] ?? 'arquivo'), 'bin')];
    }

    public function signedUrl(int $mediaId, int $ttlSeconds = 3600): string
    {
        if (!$this->media->get_by_id($mediaId)) throw new RuntimeException('Midia nao encontrada.', 404);
        $expires = time() + min(86400, max(60, $ttlSeconds));
        $secret = (string) $this->settings->get_value(Chat_settings_model::WEBHOOK_SECRET, '');
        if ($secret === '') throw new RuntimeException('Segredo para assinatura de midia nao configurado.');
        $signature = hash_hmac('sha256', $mediaId . '|' . $expires, $secret);
        $base = function_exists('get_uri') ? get_uri('chatwoot_plugin/media/' . $mediaId) : '/chatwoot_plugin/media/' . $mediaId;
        return $base . '?expires=' . $expires . '&signature=' . rawurlencode($signature);
    }

    public function verifySignature(int $mediaId, int $expires, string $signature): bool
    {
        if ($expires < time() || $expires > time() + 86400 || !preg_match('/^[a-f0-9]{64}$/', $signature)) return false;
        $secret = (string) $this->settings->get_value(Chat_settings_model::WEBHOOK_SECRET, '');
        return $secret !== '' && hash_equals(hash_hmac('sha256', $mediaId . '|' . $expires, $secret), $signature);
    }

    /** @return array{body:string,mime:string,name:string} */
    public function messageContent(int $messageId): array
    {
        $message = $this->messages->get_by_id($messageId);
        if (!$message) {
            throw new RuntimeException('Mensagem nao encontrada.', 404);
        }
        if (!empty($message['media_id'])) {
            return $this->content((int) $message['media_id']);
        }
        $instance = $this->instances->get_by_id((int) $message['instance_id']);
        if (!$instance) {
            throw new RuntimeException('Instancia da midia nao encontrada.', 404);
        }
        $url = trim((string) ($message['media_url'] ?? ''));
        $parts = parse_url($url);
        $base = trim((string) ($instance['base_url'] ?? $this->settings->get_value('evolution_base_url', '')));
        $baseParts = parse_url($base);
        $secure = (string) $this->settings->get_value('secure_media', '1') !== '0';
        $isAbsoluteProviderUrl = $url !== ''
            && is_array($parts)
            && is_array($baseParts)
            && $this->sameOrigin($parts, $baseParts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), $secure ? ['https'] : ['http', 'https'], true);
        if (!$isAbsoluteProviderUrl) {
            return $this->resolveProviderMedia($message, $instance);
        }
        $key = $this->instances->get_decrypted_api_key((int) $instance['id']) ?: (string) $this->settings->get_value('evolution_api_key', '');
        $curl = curl_init($url);
        $headers = ['Accept: */*'];
        if ($key !== '') {
            $headers[] = 'apikey: ' . $key;
        }
        $maxBytes = 32 * 1024 * 1024;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, float $downloadSize, float $downloaded) use ($maxBytes): int {
                unset($resource, $downloadSize);
                return $downloaded > $maxBytes ? 1 : 0;
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $mime = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        if ($body === false || $status < 200 || $status >= 300 || strlen($body) > $maxBytes) {
            throw new RuntimeException('Nao foi possivel obter a midia da Evolution.', 502);
        }
        return ['body' => $body, 'mime' => $mime ?: (string) ($message['mime_type'] ?? 'application/octet-stream'), 'name' => $this->safeName((string) ($message['file_name'] ?? 'arquivo'), 'bin')];
    }

    /** @return array{body:string,mime:string,name:string} */
    private function resolveProviderMedia(array $message, array $instance): array
    {
        $raw = json_decode((string) ($message['raw_payload'] ?? ''), true);
        if (!is_array($raw) || !empty($raw['_truncated'])) {
            throw new RuntimeException('A referencia segura da midia nao esta disponivel.', 404);
        }
        $providerMessage = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
        $instance['api_key'] = $this->instances->get_decrypted_api_key((int) $instance['id']) ?: '';
        $client = new Evolution_client([
            'instance' => $instance,
            'timeout' => (int) $this->settings->get_value(Chat_settings_model::EVOLUTION_TIMEOUT_SECONDS, 30),
        ], null, $this->settings);
        $response = $client->get_media_base64($providerMessage);
        if (empty($response['success']) || empty($response['base64'])) {
            throw new RuntimeException((string) ($response['error'] ?? 'Nao foi possivel resolver a midia na Evolution.'), 502);
        }
        $encoded = preg_replace('/\s+/', '', (string) $response['base64']) ?: '';
        $encoded = strtr($encoded, '-_', '+/');
        $body = base64_decode($encoded, true);
        if ($body === false || $body === '' || strlen($body) > 32 * 1024 * 1024) {
            throw new RuntimeException('A Evolution retornou uma midia invalida ou acima do limite.', 502);
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        $mime = trim((string) ($response['mime_type'] ?? '')) ?: trim((string) ($message['mime_type'] ?? ''));
        if ($detected !== '' && isset(self::MIME_TYPES[$detected])) {
            $mime = $detected;
        }
        if (!isset(self::MIME_TYPES[$mime])) {
            throw new RuntimeException('O tipo da midia recebida nao e permitido.', 422);
        }
        $name = $this->safeName((string) ($message['file_name'] ?? 'arquivo'), self::MIME_TYPES[$mime][1]);
        $mediaId = $this->cacheIncomingMedia($message, $body, $mime, $name);
        $this->messages->update_message((int) $message['id'], [
            'media_id' => $mediaId,
            'media_url' => $this->mediaUrl($mediaId),
            'file_size' => strlen($body),
            'mime_type' => $mime,
        ]);

        return ['body' => $body, 'mime' => $mime, 'name' => $name];
    }

    private function cacheIncomingMedia(array $message, string $body, string $mime, string $name): int
    {
        [$mediaType, $extension] = self::MIME_TYPES[$mime];
        $sha = hash('sha256', $body);
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Nao foi possivel armazenar a midia recebida.');
        }
        $storedName = $sha . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!is_file($path) && file_put_contents($path, $body, LOCK_EX) === false) {
            throw new RuntimeException('Nao foi possivel armazenar a midia recebida.');
        }
        return $this->media->create_record([
            'message_id' => (int) $message['id'],
            'conversation_id' => (int) $message['conversation_id'],
            'instance_id' => (int) $message['instance_id'],
            'storage_driver' => 'local',
            'storage_path' => $relativeDirectory . '/' . $storedName,
            'original_name' => $name,
            'mime_type' => $mime,
            'media_type' => $mediaType,
            'file_size' => strlen($body),
            'sha256' => $sha,
            'created_by' => null,
        ]);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameOrigin(array $left, array $right): bool
    {
        $leftScheme = strtolower((string) ($left['scheme'] ?? ''));
        $rightScheme = strtolower((string) ($right['scheme'] ?? ''));
        $leftHost = strtolower(rtrim((string) ($left['host'] ?? ''), '.'));
        $rightHost = strtolower(rtrim((string) ($right['host'] ?? ''), '.'));
        $leftPort = (int) ($left['port'] ?? ($leftScheme === 'https' ? 443 : 80));
        $rightPort = (int) ($right['port'] ?? ($rightScheme === 'https' ? 443 : 80));

        return $leftScheme !== '' && $leftHost !== ''
            && $leftScheme === $rightScheme
            && $leftHost === $rightHost
            && $leftPort === $rightPort;
    }

    private function mediaUrl(int $id): string
    {
        return function_exists('get_uri') ? get_uri('chatwoot_plugin/api/media/' . $id) : '/chatwoot_plugin/api/media/' . $id;
    }

    private function safeName(string $name, string $fallbackExtension): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo.' . $fallbackExtension;
        return mb_substr($name, 0, 180);
    }

    private function projectMessage(array $row): array
    {
        $mediaId = isset($row['media_id']) ? (int) $row['media_id'] : 0;
        return [
            'id' => (int) ($row['id'] ?? 0),
            'conversation_id' => (int) ($row['conversation_id'] ?? 0),
            'instance_id' => (int) ($row['instance_id'] ?? 0),
            'external_message_id' => $row['external_message_id'] ?? null,
            'client_message_id' => $row['client_message_id'] ?? null,
            'direction' => (string) ($row['direction'] ?? 'outgoing'),
            'message_type' => (string) ($row['message_type'] ?? 'document'),
            'text_content' => (string) ($row['text_content'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'media_id' => $mediaId ?: null,
            'media_url' => $mediaId ? $this->mediaUrl($mediaId) : (string) ($row['media_url'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'file_size' => (int) ($row['file_size'] ?? 0),
            'status' => (string) ($row['status'] ?? 'sent'),
            'delivery_error' => $row['delivery_error'] ?? null,
            'message_timestamp' => isset($row['message_timestamp']) ? (int) $row['message_timestamp'] : null,
        ];
    }
}
