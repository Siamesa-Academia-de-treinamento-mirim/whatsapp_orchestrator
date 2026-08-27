<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

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
        private ?Audit_service $audit = null,
        private ?Provider_manager $providers = null,
        private ?Media_policy_service $mediaPolicy = null,
        private ?Media_conversion_service $mediaConversion = null,
        private ?Send_lock_service $sendLocks = null,
        private ?Service_window_policy $serviceWindow = null
    ) {
        $this->media ??= new Chat_media_model();
        $this->messages ??= new Chat_messages_model();
        $this->conversations ??= new Chat_conversations_model();
        $this->instances ??= new Chat_instances_model();
        $this->settings ??= new Chat_settings_model();
        $this->audit ??= new Audit_service();
        $this->providers ??= new Provider_manager($this->instances, $this->settings);
        $this->mediaPolicy ??= new Media_policy_service();
        $this->mediaConversion ??= new Media_conversion_service(
            null,
            (string) $this->settings->get_value('media_ffmpeg_binary', ''),
            (string) $this->settings->get_value('media_ffprobe_binary', '')
        );
        $this->sendLocks ??= new Send_lock_service();
        $this->serviceWindow ??= new Service_window_policy();
    }

    public function send(
        int $conversationId,
        UploadedFile $file,
        string $caption,
        string $clientMessageId,
        int $actorId,
        ?string $requestedKind = null,
        bool $voiceNote = false,
        bool $recording = false,
        string $batchId = '',
        ?int $replyToMessageId = null
    ): array
    {
        $clientMessageId = $this->normalizeClientMessageId($clientMessageId);
        if (!$this->sendLocks->acquireFor($conversationId, $clientMessageId, 0)) {
            throw new RuntimeException('Um envio com este identificador ja esta em andamento.', 409);
        }
        try {
            $existing = $this->messages->find_by_client_message_id($conversationId, $clientMessageId);
            $sourceIdentity = $this->sourceIdentity($file);
            if ($existing) {
                $this->assertImmutableSource($existing, $sourceIdentity);
            }
            if ($existing) {
                $state = $this->idempotencyState($existing);
                if ($state === 'idempotent_success') {
                    $projected = $this->projectMessage($existing);
                    $projected['idempotency_state'] = 'idempotent_success';
                    return $projected;
                }
                if (!$this->canRetryState($state)) {
                    $projected = $this->projectMessage($existing);
                    $projected['idempotency_state'] = $state;
                    $projected['status'] = 'failed';
                    return $projected;
                }
            }
            $storedReplyId = $existing ? $this->replyTargetLocalId($existing) : null;
            if ($existing && $this->hasReplyContext($existing) && $storedReplyId === null) {
                throw new InvalidArgumentException('A mensagem original da resposta contextual nao esta mais disponivel.');
            }
            if ($existing && $storedReplyId !== null && $replyToMessageId !== null && $storedReplyId !== $replyToMessageId) {
                throw new InvalidArgumentException('O retry precisa reutilizar a mensagem original da resposta contextual.');
            }
            $effectiveReplyId = $storedReplyId ?? $replyToMessageId;
            $context = $this->sendContext($conversationId, $effectiveReplyId);
            if ($existing) {
                $logical = $this->mediaLogicalContext($existing);
                if (array_key_exists('caption', $logical)) $caption = (string) $logical['caption'];
                if (!empty($logical['kind'])) $requestedKind = (string) $logical['kind'];
                if (array_key_exists('voice_note', $logical)) $voiceNote = !empty($logical['voice_note']);
                if (array_key_exists('recording', $logical)) $recording = !empty($logical['recording']);
            }

            $prepared = $this->prepareUpload($file, $context['capabilities'], $caption, $requestedKind, $voiceNote, $recording);
            try {
                return $this->sendPrepared($context, $file, $prepared, $clientMessageId, $actorId, $batchId, $existing, $sourceIdentity);
            } finally {
                $this->cleanupPrepared($prepared);
            }
        } finally {
            $this->sendLocks->releaseFor($conversationId, $clientMessageId);
        }
    }

    /**
     * Additive batch API. Every file is preflighted before the first provider
     * call, so a rejected item can never be sent as a side effect of an earlier
     * valid item.
     *
     * @param array<int,UploadedFile> $files
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function sendBatch(int $conversationId, array $files, array $items, int $actorId, string $batchId = '', ?int $replyToMessageId = null): array
    {
        $files = array_values($files);
        $batchId = $this->normalizeBatchId($batchId, $conversationId, $files, $items);
        $replyIds = $replyToMessageId !== null ? [$replyToMessageId] : [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['reply_to_message_id']) && $item['reply_to_message_id'] !== '') {
                $replyIds[] = (int) $item['reply_to_message_id'];
            }
        }
        foreach ($files as $index => $_file) {
            $item = is_array($items[$index] ?? null) ? $items[$index] : [];
            $clientMessageId = $this->normalizeClientMessageId((string) ($item['client_message_id'] ?? ''), $batchId . '-' . $index);
            $existing = $this->messages->find_by_client_message_id($conversationId, $clientMessageId);
            if (!$existing || $this->idempotencyState($existing) === 'idempotent_success') continue;
            $storedReplyId = $this->replyTargetLocalId($existing);
            if ($this->hasReplyContext($existing) && $storedReplyId === null) {
                throw new InvalidArgumentException('A mensagem original da resposta contextual nao esta mais disponivel.');
            }
            if ($storedReplyId !== null) $replyIds[] = $storedReplyId;
        }
        $replyIds = array_values(array_unique(array_filter($replyIds, static fn ($id): bool => (int) $id > 0)));
        if (count($replyIds) > 1) {
            throw new InvalidArgumentException('Todos os anexos de um lote precisam usar a mesma mensagem de resposta.');
        }
        try {
            $context = $this->sendContext($conversationId, $replyIds[0] ?? null);
        } catch (Message_send_exception $exception) {
            $results = [];
            foreach ($files as $index => $_file) {
                $item = is_array($items[$index] ?? null) ? $items[$index] : [];
                $clientMessageId = $this->normalizeClientMessageId((string) ($item['client_message_id'] ?? ''), $batchId . '-' . $index);
                $details = $exception->details();
                $results[$index] = [
                    'index' => $index,
                    'client_message_id' => $clientMessageId,
                    'batch_id' => $batchId,
                    'status' => 'rejected',
                    'idempotency_state' => (string) ($details['idempotency_state'] ?? $details['send_state'] ?? 'rejected'),
                    'error' => $exception->getMessage(),
                    'details' => $details,
                ];
            }
            return $this->batchResult($batchId, $results);
        }
        $prepared = [];
        $results = [];
        $hasPreflightFailure = false;
        $locks = [];
        $existingRows = [];
        $sourceIdentities = [];
        $seenClientIds = [];
        try {
        foreach (array_values($files) as $index => $file) {
            $item = is_array($items[$index] ?? null) ? $items[$index] : [];
            $clientMessageId = $this->normalizeClientMessageId((string) ($item['client_message_id'] ?? ''), $batchId . '-' . $index);
            if (isset($seenClientIds[$clientMessageId])) {
                throw new InvalidArgumentException('Cada item do lote precisa de um client_message_id unico.', 422);
            }
            $seenClientIds[$clientMessageId] = true;
            $base = ['index' => $index, 'client_message_id' => $clientMessageId, 'batch_id' => $batchId];
            if (!$this->sendLocks->acquireFor($conversationId, $clientMessageId, 0)) {
                throw new RuntimeException('Um envio com este identificador ja esta em andamento.', 409);
            }
            $locks[$index] = $clientMessageId;
            $existing = $this->messages->find_by_client_message_id($conversationId, $clientMessageId);
            $existingRows[$index] = $existing;
            try {
                $sourceIdentities[$index] = $this->sourceIdentity($file);
                if ($existing) {
                    $this->assertImmutableSource($existing, $sourceIdentities[$index]);
                }
            } catch (\Throwable $exception) {
                $hasPreflightFailure = true;
                $details = $exception instanceof Media_engine_exception || $exception instanceof Message_send_exception ? $exception->details() : [];
                $results[$index] = $base + [
                    'status' => 'rejected',
                    'idempotency_state' => (string) ($details['idempotency_state'] ?? 'rejected'),
                    'error' => $exception->getMessage(),
                ];
                if ($details !== []) $results[$index]['details'] = $details;
                $prepared[$index] = null;
                continue;
            }
            if ($existing) {
                $state = $this->idempotencyState($existing);
                if ($state === 'idempotent_success') {
                    $results[$index] = $base + ['status' => 'idempotent', 'idempotency_state' => 'idempotent_success', 'message' => $this->projectMessage($existing)];
                    $prepared[$index] = null;
                    continue;
                }
                if (!$this->canRetryState($state)) {
                    $results[$index] = $base + ['status' => 'failed', 'idempotency_state' => $state, 'error' => $this->existingFailure($existing, $state)->getMessage()];
                    $prepared[$index] = null;
                    continue;
                }
            }
            if ($existing) {
                $logical = $this->mediaLogicalContext($existing);
                if (array_key_exists('caption', $logical)) $item['caption'] = (string) $logical['caption'];
                if (!empty($logical['kind'])) $item['kind'] = (string) $logical['kind'];
                if (array_key_exists('voice_note', $logical)) $item['voice_note'] = !empty($logical['voice_note']);
                if (array_key_exists('recording', $logical)) $item['recording'] = !empty($logical['recording']);
            }
            try {
                $prepared[$index] = $this->prepareUpload(
                    $file,
                    $context['capabilities'],
                    (string) ($item['caption'] ?? ''),
                    isset($item['kind']) ? (string) $item['kind'] : null,
                    !empty($item['voice_note']),
                    !empty($item['recording'])
                );
                $results[$index] = $base + ['status' => 'prepared'];
            } catch (\Throwable $exception) {
                $hasPreflightFailure = true;
                $result = $base + ['status' => 'rejected', 'idempotency_state' => 'rejected', 'error' => $exception->getMessage()];
                if ($exception instanceof Media_engine_exception || $exception instanceof Message_send_exception) {
                    $result['details'] = $exception->details();
                    $result['idempotency_state'] = (string) ($result['details']['idempotency_state'] ?? $result['details']['send_state'] ?? 'rejected');
                }
                $results[$index] = $result;
                $prepared[$index] = null;
            }
        }

        if ($hasPreflightFailure) {
            foreach ($results as $index => &$result) {
                if (($result['status'] ?? '') === 'prepared') {
                    $result['status'] = 'not_attempted';
                    $result['idempotency_state'] = 'not_attempted';
                    unset($result['message']);
                }
            }
            unset($result);
            foreach ($prepared as $descriptor) {
                if (is_array($descriptor)) {
                    $this->cleanupPrepared($descriptor);
                }
            }

            return $this->batchResult($batchId, $results);
        }

        foreach ($prepared as $index => $descriptor) {
            if (!is_array($descriptor) || ($results[$index]['status'] ?? '') === 'idempotent') {
                continue;
            }
            try {
                $results[$index]['message'] = $this->sendPrepared(
                    $context,
                    $files[$index],
                    $descriptor,
                    (string) $results[$index]['client_message_id'],
                    $actorId,
                    $batchId,
                    $existingRows[$index] ?? null,
                    $sourceIdentities[$index] ?? null
                );
                $results[$index]['status'] = 'sent';
                $results[$index]['idempotency_state'] = 'idempotent_success';
            } catch (\Throwable $exception) {
                $results[$index]['status'] = 'failed';
                $results[$index]['error'] = $exception->getMessage();
                if ($exception instanceof Media_engine_exception || $exception instanceof Message_send_exception) {
                    $details = $exception->details();
                    $results[$index]['details'] = $details;
                    $results[$index]['idempotency_state'] = (string) ($details['idempotency_state'] ?? $details['send_state'] ?? 'ambiguous_failure');
                } else {
                    $results[$index]['idempotency_state'] = 'ambiguous_failure';
                }
            } finally {
                $this->cleanupPrepared($descriptor);
            }
            unset($item);
        }
        return $this->batchResult($batchId, $results);
        } finally {
            foreach ($locks as $index => $clientId) {
                $this->sendLocks->releaseFor($conversationId, (string) $clientId);
            }
        }
    }

    /** @return array<string,mixed> */
    private function sendContext(int $conversationId, ?int $replyToMessageId = null): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) {
            throw new RuntimeException('Conversa nao encontrada.', 404);
        }
        $instance = $this->instances->get_by_id((int) $conversation['instance_id']);
        if (!$instance || empty($instance['active']) || (string) ($instance['connection_status'] ?? '') !== 'connected') {
            throw new RuntimeException('A instancia esta desconectada; o envio foi bloqueado.', 409);
        }
        $provider = $this->providers->forInstance($instance);
        $capabilities = $provider->getCapabilities();
        if (empty($capabilities['actions']['send_media'])) {
            throw new RuntimeException('O provedor nao permite o envio de midia.', 422);
        }
        $replyTarget = $this->resolveReplyTarget($conversationId, $replyToMessageId, $capabilities);
        if (str_ends_with(strtolower((string) $conversation['remote_jid']), '@g.us') && empty($capabilities['conversation']['groups'])) {
            throw new RuntimeException('O provedor deste canal nao suporta grupos.', 422);
        }
        $this->serviceWindow->assertFreeformAllowed($conversation, $capabilities, 'midia');

        return [
            'conversation_id' => $conversationId,
            'conversation' => $conversation,
            'instance' => $instance,
            'provider' => $provider,
            'capabilities' => $capabilities,
            'number' => $this->resolveRecipient($conversation),
            'reply_target' => $replyTarget,
        ];
    }

    /** @return array<string,mixed> */
    private function prepareUpload(UploadedFile $file, array $capabilities, string $caption, ?string $kind, bool $voiceNote, bool $recording): array
    {
        $prepared = $this->mediaPolicy->validateUploadedFile($file, $capabilities, $caption, $kind, $voiceNote, $recording);
        $this->mediaConversion->assertProviderVideoCompatible($file->getTempName(), (array) ($prepared['policy'] ?? []));
        $prepared['source_path'] = $file->getTempName();
        $prepared['cleanup_path'] = null;
        $prepared['converted'] = false;
        if (!empty($prepared['needs_conversion'])) {
            $converted = $this->mediaConversion->toVoiceCompatible($file->getTempName());
            $postConversion = $this->mediaPolicy->validatePath(
                $converted['path'],
                pathinfo($file->getClientName(), PATHINFO_FILENAME) . '.ogg',
                $capabilities,
                $caption,
                'audio',
                true,
                false,
                false
            );
            $postConversion['mime_type'] = $converted['mime_type'];
            $postConversion['detected_mime_type'] = 'audio/ogg';
            $postConversion['extension'] = 'ogg';
            $postConversion['filename'] = $this->safeName(pathinfo($file->getClientName(), PATHINFO_FILENAME) . '.ogg', 'ogg');
            $postConversion['voice_note'] = $voiceNote;
            $postConversion['source_mime_type'] = $prepared['detected_mime_type'];
            $postConversion['source_path'] = $converted['path'];
            $postConversion['cleanup_path'] = $converted['cleanup_path'];
            $postConversion['converted'] = true;
            return $postConversion;
        }

        $prepared['source_mime_type'] = $prepared['detected_mime_type'];
        return $prepared;
    }

    /** @return array<string,mixed> */
    private function sendPrepared(array $context, UploadedFile $file, array $prepared, string $clientMessageId, int $actorId, string $batchId = '', ?array $existing = null, ?array $sourceIdentity = null): array
    {
        $conversationId = (int) $context['conversation_id'];
        $conversation = $context['conversation'];
        $instance = $context['instance'];
        $provider = $context['provider'];
        $mediaType = (string) $prepared['kind'];
        $mime = (string) $prepared['mime_type'];
        $size = (int) $prepared['size'];
        $original = (string) $prepared['filename'];
        $sourcePath = (string) $prepared['source_path'];
        $sourceIdentity ??= $this->sourceIdentity($file);
        $sha = hash_file('sha256', $sourcePath);
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Nao foi possivel preparar o armazenamento de midia.', 422);
        }
        $storedName = $sha . '-' . bin2hex(random_bytes(4)) . '.' . (string) $prepared['extension'];
        if (!empty($prepared['converted'])) {
            if (!copy($sourcePath, $directory . DIRECTORY_SEPARATOR . $storedName)) {
                throw new RuntimeException('Nao foi possivel armazenar a midia convertida.', 422);
            }
        } else {
            $file->move($directory, $storedName, true);
        }
        $relativePath = $relativeDirectory . '/' . $storedName;
        $mediaId = (int) ($existing['media_id'] ?? 0);
        $mediaData = [
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
        ];
        if ($mediaId > 0) {
            $this->media->update_record($mediaId, $mediaData);
        } else {
            $mediaId = $this->media->create_record($mediaData);
        }
        $now = time();
        $messageData = [
            'remote_jid' => (string) $conversation['remote_jid'],
            'direction' => 'outgoing',
            'message_type' => $mediaType,
            'text_content' => (string) $prepared['caption'],
            'caption' => (string) $prepared['caption'],
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
            'reply_to_external_message_id' => $context['reply_target']['external_message_id'] ?? null,
            'raw_payload' => [
                'source' => 'rise_media_engine_v2',
                'sha256' => $sha,
                'media_engine' => [
                    'provider' => $provider->name(),
                    'client_message_id' => $clientMessageId,
                    'source_sha256' => (string) $sourceIdentity['source_sha256'],
                    'source_size' => (int) $sourceIdentity['source_size'],
                    'source_detected_mime' => (string) $sourceIdentity['source_detected_mime'],
                    'caption' => (string) $prepared['caption'],
                    'kind' => $mediaType,
                    'source_mime_type' => $prepared['source_mime_type'],
                    'output_mime_type' => $mime,
                    'converted' => !empty($prepared['converted']),
                    'voice_note' => !empty($prepared['voice_note']),
                    'recording' => !empty($prepared['recording']),
                    'batch_id' => $batchId !== '' ? $batchId : null,
                    'reply_to_local_message_id' => $context['reply_target']['local_message_id'] ?? null,
                    'reply_to_external_message_id' => $context['reply_target']['external_message_id'] ?? null,
                    'idempotency_state' => 'awaiting_provider',
                ],
            ],
        ];
        $messageId = (int) ($existing['id'] ?? 0);
        if ($messageId > 0) {
            $this->messages->update_message($messageId, $messageData);
        } else {
            $messageId = $this->messages->upsert_message($conversationId, (int) $instance['id'], $messageData);
        }
        $this->media->update_record($mediaId, ['message_id' => $messageId]);

        $mediaPayload = [
            'type' => $mediaType,
            'mime_type' => $mime,
            'filename' => $original,
            'caption' => (string) $prepared['caption'],
            'voice_note' => !empty($prepared['voice_note']),
            'file_size' => $size,
        ];
        if (!empty($prepared['policy']['requires_https_link'])) {
            $link = $this->signedUrl($mediaId, 86400);
            if (!str_starts_with(strtolower($link), 'https://')) {
                $this->markMediaFailed($messageId, $instance, $mediaId, $provider->name(), 'A API oficial exige uma URL HTTPS publica para enviar a midia.', $actorId, 'retryable_failure');
                throw new Media_engine_exception('MEDIA_PUBLIC_LINK_UNAVAILABLE', 'A API oficial exige uma URL HTTPS publica para enviar a midia.', 422, null, 'retryable_failure');
            }
            $mediaPayload['link'] = $link;
        } else {
            $body = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
            if ($body === false) {
                $this->markMediaFailed($messageId, $instance, $mediaId, $provider->name(), 'Nao foi possivel ler a midia armazenada.', $actorId, 'retryable_failure');
                throw new Media_engine_exception('MEDIA_STORAGE_READ_FAILED', 'Nao foi possivel ler a midia armazenada.', 422, null, 'retryable_failure');
            }
            $mediaPayload['data'] = base64_encode($body);
        }
        try {
            $response = $provider->sendMedia((string) $context['number'], $mediaPayload, [
                'conversation_id' => $conversationId,
                'client_message_id' => $clientMessageId,
                'batch_id' => $batchId,
                'voice_note' => !empty($prepared['voice_note']),
                'reply_to_external_message_id' => $context['reply_target']['external_message_id'] ?? null,
                'reply_to_remote_jid' => $context['reply_target']['remote_jid'] ?? null,
                'reply_to_from_me' => !empty($context['reply_target']['from_me']),
            ]);
        } catch (\Throwable $exception) {
            $error = mb_substr($exception->getMessage() ?: 'O provedor recusou a midia.', 0, 1000);
            $state = $exception instanceof Media_engine_exception
                ? (string) ($exception->details()['idempotency_state'] ?? 'ambiguous_failure')
                : 'ambiguous_failure';
            $this->markMediaFailed($messageId, $instance, $mediaId, $provider->name(), $error, $actorId, $state);
            if ($exception instanceof Media_engine_exception) throw $exception;
            throw new Media_engine_exception('MEDIA_PROVIDER_AMBIGUOUS', $error, 409, $exception, 'ambiguous_failure');
        }
        if (empty($response['success'])) {
            $error = mb_substr((string) ($response['error'] ?? 'O provedor nao confirmou o envio.'), 0, 1000);
            $state = $this->providerFailureState($response);
            $this->markMediaFailed($messageId, $instance, $mediaId, $provider->name(), $error, $actorId, $state);
            throw new Media_engine_exception($state === 'rejected' ? 'MEDIA_PROVIDER_REJECTED' : 'MEDIA_PROVIDER_AMBIGUOUS', $error, $state === 'ambiguous_failure' ? 409 : 422, null, $state, ['provider_status' => (int) ($response['status_code'] ?? 0), 'provider_code' => $response['error_code'] ?? null]);
        }
        $externalId = trim((string) ($response['message_id'] ?? '')) ?: null;
        $this->updateMediaEngineState($messageId, 'idempotent_success');
        $this->messages->update_message($messageId, ['external_message_id' => $externalId, 'provider_name' => $provider->name(), 'provider_payload_id' => $externalId, 'status' => 'sent', 'delivery_error' => null, 'failed_at' => null]);
        $preview = (string) $prepared['caption'] !== '' ? (string) $prepared['caption'] : '[' . $mediaType . '] ' . $original;
        $this->conversations->upsert_conversation((int) $instance['id'], (string) $conversation['remote_jid'], ['last_message_preview' => $preview, 'last_message_at' => gmdate('Y-m-d H:i:s', $now), 'last_human_message_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ($actorId > 0) {
            try {
                (new Bot_service())->pauseConversation($conversationId, $actorId, 'human_media');
            } catch (\Throwable $exception) {
                log_message('error', 'Could not pause deterministic bot after human media: {message}', ['message' => $exception->getMessage()]);
            }
        }
        $saved = $this->messages->get_by_id($messageId) ?: [];
        $this->audit->record($actorId, 'message.media_sent', 'message', $messageId, (int) $instance['id'], [], ['media_id' => $mediaId, 'message_type' => $mediaType, 'voice_note' => !empty($prepared['voice_note'])]);
        return $this->projectMessage($saved);
    }

    /** @param array<string,mixed> $prepared */
    private function cleanupPrepared(array $prepared): void
    {
        $path = trim((string) ($prepared['cleanup_path'] ?? ''));
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $conversation */
    private function resolveRecipient(array $conversation): string
    {
        $remoteJid = (string) ($conversation['remote_jid'] ?? '');
        $number = (string) (($conversation['phone_number'] ?? '') ?: $remoteJid);
        if (str_ends_with($remoteJid, '@g.us')) {
            return $remoteJid;
        }
        if (str_ends_with($remoteJid, '@lid')) {
            $lid = preg_replace('/\D+/', '', strstr($remoteJid, '@', true) ?: '');
            $number = preg_replace('/\D+/', '', $number) ?: '';
            if ($number === '' || $number === $lid) {
                throw new RuntimeException('O numero real deste contato @lid ainda nao foi resolvido.', 409);
            }
        }

        return $number;
    }

    /** @return array<string,mixed> */
    private function resolveReplyTarget(int $conversationId, ?int $replyToMessageId, array $capabilities): array
    {
        if ($replyToMessageId === null) return [];
        if (empty($capabilities['actions']['reply'])) {
            throw new InvalidArgumentException('Este provedor nao suporta respostas contextuais.', 422);
        }
        $target = $this->messages->get_by_id($replyToMessageId);
        $externalId = $target ? trim((string) ($target['external_message_id'] ?? '')) : '';
        if (!$target || (int) ($target['conversation_id'] ?? 0) !== $conversationId
            || !empty($target['is_internal_note'])
            || strtolower(trim((string) ($target['direction'] ?? ''))) === 'internal'
            || strtolower(trim((string) ($target['status'] ?? ''))) === 'failed'
            || $externalId === ''
            || strlen($externalId) > 191
            || preg_match('/[\x00-\x20\x7F]/', $externalId) === 1) {
            throw new InvalidArgumentException('A mensagem escolhida nao pode ser usada como resposta contextual.', 422);
        }
        return [
            'external_message_id' => $externalId,
            'local_message_id' => (int) $target['id'],
            'remote_jid' => trim((string) ($target['remote_jid'] ?? '')),
            'from_me' => strtolower(trim((string) ($target['direction'] ?? ''))) === 'outgoing',
        ];
    }

    private function hasReplyContext(array $row): bool
    {
        return $this->replyTargetLocalId($row) !== null
            || trim((string) ($row['reply_to_external_message_id'] ?? '')) !== '';
    }

    /** @return array<string,mixed> */
    private function mediaLogicalContext(array $row): array
    {
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        $context = is_array($raw['media_engine'] ?? null) ? $raw['media_engine'] : [];
        if (!array_key_exists('caption', $context)) $context['caption'] = (string) ($row['caption'] ?? '');
        if (!array_key_exists('kind', $context)) $context['kind'] = (string) ($row['message_type'] ?? '');
        if (!array_key_exists('voice_note', $context)) $context['voice_note'] = !empty($row['is_voice_note']);
        if (!array_key_exists('recording', $context)) $context['recording'] = false;
        return $context;
    }

    /** @return array{source_sha256:string,source_size:int,source_detected_mime:string} */
    private function sourceIdentity(UploadedFile $file): array
    {
        if (!$file->isValid() || $file->hasMoved() || !is_file($file->getTempName())) {
            throw new Media_engine_exception('MEDIA_SOURCE_IDENTITY_FAILED', 'A identidade da midia nao pode ser calculada.');
        }
        $sha256 = hash_file('sha256', $file->getTempName());
        if (!is_string($sha256) || $sha256 === '') {
            throw new Media_engine_exception('MEDIA_SOURCE_IDENTITY_FAILED', 'A identidade da midia nao pode ser calculada.');
        }

        return [
            'source_sha256' => strtolower($sha256),
            'source_size' => (int) filesize($file->getTempName()),
            'source_detected_mime' => Media_policy_service::detectMime($file->getTempName()),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sourceIdentity */
    private function assertImmutableSource(array $row, array $sourceIdentity): void
    {
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        $engine = is_array($raw['media_engine'] ?? null) ? $raw['media_engine'] : [];
        $originalSha = strtolower(trim((string) ($engine['source_sha256'] ?? '')));
        if ($originalSha === '') {
            throw new Media_engine_exception(
                'MEDIA_SOURCE_IDENTITY_UNAVAILABLE',
                'Este envio antigo nao possui identidade de origem verificavel; o retry foi bloqueado.',
                409
            );
        }
        if (!hash_equals($originalSha, strtolower((string) $sourceIdentity['source_sha256']))) {
            throw new Media_engine_exception(
                'IDEMPOTENCY_PAYLOAD_MISMATCH',
                'O client_message_id ja representa outro arquivo. Use um novo identificador para uma nova midia.',
                409
            );
        }
    }

    private function replyTargetLocalId(array $row): ?int
    {
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        $candidate = $raw['media_engine']['reply_to_local_message_id'] ?? null;
        if (is_numeric($candidate) && (int) $candidate > 0) return (int) $candidate;
        $externalId = trim((string) ($row['reply_to_external_message_id'] ?? ''));
        if ($externalId === '') return null;
        $target = $this->messages->find_by_external_id((int) ($row['instance_id'] ?? 0), $externalId);
        return $target && (int) ($target['id'] ?? 0) > 0 ? (int) $target['id'] : null;
    }

    private function normalizeClientMessageId(string $clientMessageId, ?string $fallback = null): string
    {
        $clientMessageId = trim($clientMessageId) ?: trim((string) $fallback);
        if ($clientMessageId === '') {
            $clientMessageId = 'media-' . bin2hex(random_bytes(12));
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $clientMessageId)) {
            throw new InvalidArgumentException('Identificador idempotente do anexo invalido.', 422);
        }

        return $clientMessageId;
    }

    /** @param array<int,UploadedFile> $files @param array<int,array<string,mixed>> $items */
    private function normalizeBatchId(string $batchId, int $conversationId, array $files, array $items): string
    {
        $batchId = trim($batchId);
        if ($batchId !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $batchId)) {
                throw new InvalidArgumentException('Identificador do lote invalido.', 422);
            }
            return $batchId;
        }
        $fingerprints = [];
        foreach ($files as $index => $file) {
            $path = $file instanceof UploadedFile ? $file->getTempName() : '';
            $fingerprints[] = hash_file('sha256', $path) ?: (string) $index;
            $fingerprints[] = (string) ($items[$index]['client_message_id'] ?? '');
        }

        return 'batch-' . substr(hash('sha256', $conversationId . '|' . implode('|', $fingerprints)), 0, 32);
    }

    /** @param array<int,array<string,mixed>> $results @return array<string,mixed> */
    private function batchResult(string $batchId, array $results): array
    {
        ksort($results, SORT_NUMERIC);
        $items = array_values($results);
        $failed = array_values(array_filter($items, static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['rejected', 'failed', 'not_attempted'], true)));

        return [
            'batch_id' => $batchId,
            'items' => $items,
            'total' => count($items),
            'succeeded' => count(array_filter($items, static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['sent', 'idempotent'], true))),
            'failed' => count($failed),
            'has_failures' => $failed !== [],
        ];
    }

    private function markMediaFailed(int $messageId, array $instance, int $mediaId, string $provider, string $error, int $actorId, string $idempotencyState = 'ambiguous_failure'): void
    {
        $this->updateMediaEngineState($messageId, $idempotencyState);
        $this->messages->update_message($messageId, [
            'status' => 'failed',
            'provider_name' => $provider,
            'delivery_error' => mb_substr($error, 0, 1000),
            'failed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->record($actorId, 'message.media_failed', 'message', $messageId, (int) $instance['id'], [], ['media_id' => $mediaId, 'provider' => $provider, 'error' => mb_substr($error, 0, 300)]);
    }

    /**
     * Success is only a persisted provider acceptance. A historical failed
     * row without an explicit safe phase is treated as ambiguous and is never
     * resent blindly.
     */
    private function idempotencyState(array $row): string
    {
        if (in_array(strtolower((string) ($row['status'] ?? '')), ['sent', 'delivered', 'read'], true)) {
            return 'idempotent_success';
        }
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        $state = is_scalar($raw['media_engine']['idempotency_state'] ?? null)
            ? strtolower(trim((string) $raw['media_engine']['idempotency_state']))
            : '';
        if (in_array($state, ['retryable_failure', 'ambiguous_failure', 'rejected', 'not_attempted'], true)) return $state;
        return 'ambiguous_failure';
    }

    private function existingFailure(array $row, string $state): RuntimeException
    {
        $error = trim((string) ($row['delivery_error'] ?? ''));
        if ($state === 'retryable_failure') {
            return new RuntimeException($error !== '' ? $error : 'A midia falhou antes da aceitacao do provedor e pode ser reenviada.', 422);
        }
        if ($state === 'rejected') {
            return new RuntimeException($error !== '' ? $error : 'A midia foi rejeitada e nao pode ser reenviada com este identificador.', 422);
        }
        return new RuntimeException($error !== '' ? $error : 'O resultado do envio de midia e ambiguo; o mesmo identificador nao sera reenviado automaticamente.', 409);
    }

    private function canRetryState(string $state): bool
    {
        return in_array($state, ['retryable_failure', 'not_attempted'], true);
    }

    /** @param array<string,mixed> $response */
    private function providerFailureState(array $response): string
    {
        $status = (int) ($response['status_code'] ?? 0);
        if ($status === 429) return 'retryable_failure';
        if ($status === 408 || $status === 0 || $status >= 500) return 'ambiguous_failure';
        if ($status >= 400 && $status < 500) return 'rejected';
        return 'ambiguous_failure';
    }

    private function updateMediaEngineState(int $messageId, string $state): void
    {
        $row = $this->messages->get_by_id($messageId);
        if (!$row) return;
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        if (!is_array($raw)) $raw = [];
        if (!is_array($raw['media_engine'] ?? null)) $raw['media_engine'] = [];
        $raw['media_engine']['idempotency_state'] = $state;
        $this->messages->update_message($messageId, ['raw_payload' => $raw]);
    }

    public function upload(UploadedFile $file, int $actorId, ?int $instanceId = null): array
    {
        if (!$file->isValid() || $file->hasMoved()) throw new InvalidArgumentException('Arquivo de midia invalido.');
        $providerDescriptor = null;
        if ($instanceId) {
            $instance = $this->instances->get_by_id($instanceId);
            if (!$instance) throw new InvalidArgumentException('Instancia da midia invalida.');
            $provider = $this->providers->forInstance($instance);
            $providerDescriptor = $this->mediaPolicy->validateUploadedFile($file, $provider->getCapabilities());
            $this->mediaConversion->assertProviderVideoCompatible($file->getTempName(), (array) ($providerDescriptor['policy'] ?? []));
        }
        if (is_array($providerDescriptor)) {
            $size = (int) $providerDescriptor['size'];
            $mime = (string) $providerDescriptor['mime_type'];
            $mediaType = (string) $providerDescriptor['kind'];
            $extension = (string) $providerDescriptor['extension'];
            $originalName = (string) $providerDescriptor['filename'];
        } else {
            $maxBytes = min(64, max(1, (int) $this->settings->get_value('media_max_upload_mb', 16))) * 1024 * 1024;
            $size = (int) $file->getSize();
            if ($size < 1 || $size > $maxBytes) throw new InvalidArgumentException('O arquivo excede o limite configurado.');
            $mime = Media_policy_service::detectMime($file->getTempName());
            if (!isset(self::MIME_TYPES[$mime])) throw new InvalidArgumentException('Tipo de arquivo nao permitido.');
            [$mediaType, $extension] = self::MIME_TYPES[$mime];
            $originalName = $this->safeName($file->getClientName(), $extension);
        }
        $sha = hash_file('sha256', $file->getTempName());
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Nao foi possivel preparar o armazenamento de midia.');
        $storedName = $sha . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $file->move($directory, $storedName, true);
        $id = $this->media->create_record(['instance_id'=>$instanceId,'storage_driver'=>'local','storage_path'=>$relativeDirectory.'/'.$storedName,'original_name'=>$originalName,'mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size,'sha256'=>$sha,'created_by'=>$actorId]);
        $this->audit->record($actorId, 'media.uploaded', 'media', $id, $instanceId, [], ['mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size]);
        return ['id'=>$id,'media_id'=>$id,'url'=>$this->mediaUrl($id),'name'=>$originalName,'mime_type'=>$mime,'media_type'=>$mediaType,'file_size'=>$size];
    }

    /** Store a conversation-owned template header without contacting a provider. */
    public function uploadTemplateMedia(UploadedFile $file, int $conversationId, int $actorId, string $expectedKind): array
    {
        $conversation = $this->conversations->get_by_id($conversationId);
        if (!$conversation) throw new InvalidArgumentException('Conversa nao encontrada.', 404);
        $instance = $this->instances->get_by_id((int) ($conversation['instance_id'] ?? 0));
        if (!$instance || empty($instance['active'])) throw new RuntimeException('Instancia inativa.', 409);
        $provider = $this->providers->forInstance($instance);
        if (empty($provider->getCapabilities()['actions']['send_template'])) {
            throw new Message_send_exception('Este canal nao suporta templates oficiais.', 'rejected', 422, null, 'TEMPLATES_NOT_SUPPORTED');
        }
        $descriptor = $this->mediaPolicy->validateUploadedFile($file, $provider->getCapabilities(), '', $expectedKind, false, false);
        if (!empty($descriptor['needs_conversion'])) {
            throw new Message_send_exception('A conversao de midia para header de template nao e suportada.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        }
        $sha = hash_file('sha256', $file->getTempName());
        $relativeDirectory = 'chatwoot_plugin/' . gmdate('Y/m');
        $root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Nao foi possivel preparar o armazenamento de midia.', 422);
        $storedName = $sha . '-' . bin2hex(random_bytes(4)) . '.' . (string) $descriptor['extension'];
        $file->move($directory, $storedName, true);
        $id = $this->media->create_record([
            'conversation_id' => $conversationId,
            'instance_id' => (int) $instance['id'],
            'storage_driver' => 'local',
            'storage_path' => $relativeDirectory . '/' . $storedName,
            'original_name' => (string) $descriptor['filename'],
            'mime_type' => (string) $descriptor['mime_type'],
            'media_type' => (string) $descriptor['kind'],
            'file_size' => (int) $descriptor['size'],
            'sha256' => $sha,
            'created_by' => $actorId,
        ]);
        return [
            'local_media_id' => $id,
            'name' => (string) $descriptor['filename'],
            'kind' => (string) $descriptor['kind'],
            'mime_type' => (string) $descriptor['mime_type'],
            'file_size' => (int) $descriptor['size'],
            'preview_url' => function_exists('get_uri') ? get_uri('chatwoot_plugin/media/' . $id) : '/chatwoot_plugin/media/' . $id,
        ];
    }

    /** Resolve local template media into the provider's secure reference. */
    public function resolveTemplateMedia(int $mediaId, int $conversationId, int $instanceId, string $expectedKind, array $capabilities): array
    {
        $row = $this->media->findOwnedTemplateMedia($mediaId, $conversationId, $instanceId);
        if (!$row) throw new Message_send_exception('A midia do template nao pertence a esta conversa.', 'rejected', 422, null, 'TEMPLATE_MEDIA_NOT_OWNED');
        if (strtolower((string) ($row['media_type'] ?? '')) !== strtolower($expectedKind)) {
            throw new Message_send_exception('O tipo da midia nao corresponde ao header do template.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        }
        $path = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($row['storage_path'] ?? '')));
        $root = realpath(rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads');
        if (!$path || !$root || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path)) {
            throw new Message_send_exception('A midia local do template nao esta disponivel.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        }
        $descriptor = $this->mediaPolicy->validatePath($path, (string) ($row['original_name'] ?? 'arquivo'), $capabilities, '', $expectedKind, false, false, false);
        if (!empty($capabilities['media'][$expectedKind]['requires_https_link'])) {
            $link = $this->signedUrl($mediaId, 86400);
            if (!str_starts_with(strtolower($link), 'https://')) {
                throw new Message_send_exception('A midia do template nao possui URL HTTPS publica assinavel.', 'rejected', 422, null, 'TEMPLATE_MEDIA_LINK_UNAVAILABLE');
            }
            return ['kind' => $expectedKind, 'link' => $link, 'local_media_id' => $mediaId, 'mime_type' => $descriptor['mime_type']];
        }
        $providerMediaId = trim((string) ($row['external_media_id'] ?? ''));
        if ($providerMediaId === '') throw new Message_send_exception('A midia do template nao possui referencia segura do provedor.', 'rejected', 422, null, 'TEMPLATE_MEDIA_LINK_UNAVAILABLE');
        return ['kind' => $expectedKind, 'id' => $providerMediaId, 'local_media_id' => $mediaId, 'mime_type' => $descriptor['mime_type']];
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
        $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
        $engine = is_array($raw['media_engine'] ?? null) ? $raw['media_engine'] : [];
        $replyExternalId = trim((string) ($row['reply_to_external_message_id'] ?? $engine['reply_to_external_message_id'] ?? ''));
        $replyLocalId = $this->replyTargetLocalId($row);
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
            'is_voice_note' => !empty($engine['voice_note']),
            'batch_id' => $engine['batch_id'] ?? null,
            'idempotency_state' => $this->idempotencyState($row),
            'reply_to' => $replyExternalId !== '' ? [
                'provider_message_id' => $replyExternalId,
                'message_id' => $replyLocalId,
                'local_message_id' => $replyLocalId,
                'resolved' => $replyLocalId !== null,
            ] : null,
            'metadata' => ['send_state' => $this->idempotencyState($row)],
            'status' => (string) ($row['status'] ?? 'sent'),
            'delivery_error' => $row['delivery_error'] ?? null,
            'message_timestamp' => isset($row['message_timestamp']) ? (int) $row['message_timestamp'] : null,
        ];
    }
}
