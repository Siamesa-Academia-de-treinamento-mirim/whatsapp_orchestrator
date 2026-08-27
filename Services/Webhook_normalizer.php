<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

/**
 * Normalizes Evolution API v2 and already-normalized n8n webhook envelopes.
 */
class Webhook_normalizer
{
    /**
     * @param array<string, mixed>|object|string $payload
     * @return array<string, mixed>
     */
    public function normalize($payload): array
    {
        $root = $this->decode($payload);
        if ($root === []) {
            return $this->emptyResult();
        }

        if (array_key_exists('body', $root)) {
            $body = $this->decode($root['body']);
            if ($body !== []) {
                $root = $this->hasEnvelopeFields($root) ? array_replace($root, $body) : $body;
                unset($root['body']);
            }
        }

        $data = $this->toArray($root['data'] ?? []);
        if ($this->isList($data)) {
            $data = $this->toArray($data[0] ?? []);
        }

        $message = $this->toArray($data['message'] ?? $root['message'] ?? []);
        $event = $this->normalizeEvent($this->firstScalar([
            $root['event'] ?? null,
            $root['event_name'] ?? null,
            $root['eventName'] ?? null,
            $root['event_type'] ?? null,
        ]));
        $instance = $this->extractInstance($root, $data);
        $externalMessageId = $this->firstScalar([
            $root['external_message_id'] ?? null,
            $root['externalMessageId'] ?? null,
            $root['external_id'] ?? null,
            $root['message_id'] ?? null,
            $root['messageId'] ?? null,
            $root['idMessage'] ?? null,
            $this->path($root, ['key', 'id']),
            $this->path($data, ['key', 'id']),
            $this->path($data, ['message', 'key', 'id']),
            $data['message_id'] ?? null,
            $data['messageId'] ?? null,
            $data['idMessage'] ?? null,
        ]);
        $externalEventId = $this->firstScalar([
            $root['external_event_id'] ?? null,
            $root['event_id'] ?? null,
            $root['webhook_id'] ?? null,
        ]);
        $remoteJid = $this->firstScalar([
            $root['remote_jid'] ?? null,
            $root['remoteJid'] ?? null,
            $this->path($root, ['key', 'remoteJid']),
            $data['remote_jid'] ?? null,
            $data['remoteJid'] ?? null,
            $this->path($data, ['key', 'remoteJid']),
            $this->path($data, ['message', 'key', 'remoteJid']),
        ]);
        $alternateJid = $this->firstScalar([
            $root['remote_jid_alt'] ?? null,
            $root['remoteJidAlt'] ?? null,
            $this->path($root, ['key', 'remoteJidAlt']),
            $data['remote_jid_alt'] ?? null,
            $data['remoteJidAlt'] ?? null,
            $this->path($data, ['key', 'remoteJidAlt']),
            $this->path($data, ['message', 'key', 'remoteJidAlt']),
        ]);
        $explicitPhone = $this->firstScalar([
            $root['phone_number'] ?? null,
            $root['phone'] ?? null,
            $data['phone_number'] ?? null,
            $data['phone'] ?? null,
        ]);
        $fromMe = $this->toBool($this->firstValue([
            $root['from_me'] ?? null,
            $root['fromMe'] ?? null,
            $this->path($root, ['key', 'fromMe']),
            $data['from_me'] ?? null,
            $data['fromMe'] ?? null,
            $this->path($data, ['key', 'fromMe']),
            $this->path($data, ['message', 'key', 'fromMe']),
        ]));
        $isGroup = $this->isGroupJid($remoteJid);
        $explicitContactName = $this->firstScalar([
            $root['contact_name'] ?? null,
            $root['contactName'] ?? null,
            $data['contact_name'] ?? null,
            $data['contactName'] ?? null,
        ]);
        $pushName = $this->firstScalar([
            $root['pushName'] ?? null,
            $root['notifyName'] ?? null,
            $data['pushName'] ?? null,
            $data['notifyName'] ?? null,
            !$isGroup ? ($data['name'] ?? null) : null,
        ]);
        $participantJid = $this->firstScalar([
            $root['participant_jid'] ?? null,
            $root['participantJid'] ?? null,
            $root['participant'] ?? null,
            $root['sender'] ?? null,
            $this->path($root, ['key', 'participant']),
            $this->path($root, ['key', 'participantAlt']),
            $data['participant_jid'] ?? null,
            $data['participantJid'] ?? null,
            $data['participant'] ?? null,
            $data['sender'] ?? null,
            $this->path($data, ['key', 'participant']),
            $this->path($data, ['key', 'participantAlt']),
            $this->path($data, ['message', 'key', 'participant']),
            $this->path($data, ['message', 'key', 'participantAlt']),
        ]);
        $participantAlternateJid = $this->firstScalar([
            $root['participant_jid_alt'] ?? null,
            $root['participantJidAlt'] ?? null,
            $this->path($root, ['key', 'participantAlt']),
            $data['participant_jid_alt'] ?? null,
            $data['participantJidAlt'] ?? null,
            $this->path($data, ['key', 'participantAlt']),
            $this->path($data, ['message', 'key', 'participantAlt']),
        ]);
        $groupName = $this->firstScalar([
            $root['group_name'] ?? null,
            $root['groupName'] ?? null,
            $root['subject'] ?? null,
            $data['group_name'] ?? null,
            $data['groupName'] ?? null,
            $data['subject'] ?? null,
            $this->path($data, ['chat', 'name']),
            $this->path($data, ['chat', 'subject']),
        ]);
        // pushName on outgoing events normally belongs to the connected account,
        // not to the recipient. It must never rename the customer contact.
        $contactName = $isGroup
            ? $groupName
            : ($fromMe ? '' : ($explicitContactName !== '' ? $explicitContactName : $pushName));
        $senderName = $this->firstScalar([
            $root['sender_name'] ?? null,
            $root['senderName'] ?? null,
            $root['participant_name'] ?? null,
            $data['sender_name'] ?? null,
            $data['senderName'] ?? null,
            $data['participant_name'] ?? null,
            $isGroup && !$fromMe ? $pushName : null,
        ]);
        $timestamp = $this->normalizeTimestamp($this->firstValue([
            $root['timestamp'] ?? null,
            $root['sent_at'] ?? null,
            $root['messageTimestamp'] ?? null,
            $data['timestamp'] ?? null,
            $data['sent_at'] ?? null,
            $data['messageTimestamp'] ?? null,
            $this->path($data, ['message', 'messageTimestamp']),
        ]));

        $media = $this->extractMedia($root, $data, $message);
        $messageType = $this->extractMessageType($root, $data, $message, $media['node_type']);
        $text = $this->extractText($root, $data, $message, $media['node']);
        $structured = $this->extractStructuredContent($root, $data, $message, $messageType, $media);
        if ($messageType === 'reaction' && is_array($structured['reaction'] ?? null)) {
            $structured['reaction']['reactor_key'] = $fromMe
                ? 'self'
                : ($isGroup ? ($participantJid ?: $participantAlternateJid) : ($remoteJid ?: $alternateJid));
            $structured['reaction']['provider_event_id'] = $externalEventId !== '' ? $externalEventId : $externalMessageId;
            $structured['reaction']['from_me'] = $fromMe;
            $structured['reaction']['provider_timestamp'] = $timestamp;
        }
        $replyToExternalId = $this->extractReplyToExternalId($root, $data, $message);
        $sourceStatus = $this->normalizeStatus($this->firstScalar([
            $root['status'] ?? null,
            $root['message_status'] ?? null,
            $root['messageStatus'] ?? null,
            $data['status'] ?? null,
            $data['message_status'] ?? null,
            $this->path($data, ['update', 'status']),
            $this->path($data, ['message', 'status']),
            $data['state'] ?? null,
        ]));
        $mappedStatus = $this->mapMessageStatus($sourceStatus);

        $normalized = [
            'event' => $event,
            'instance' => $instance,
            'instance_name' => $instance,
            'external_event_id' => $externalEventId !== '' ? $externalEventId : null,
            'external_message_id' => $externalMessageId !== '' ? $externalMessageId : null,
            'remote_jid' => $remoteJid,
            'alternate_remote_jid' => $alternateJid,
            'phone_number' => $isGroup ? '' : $this->phoneNumber($remoteJid, $alternateJid, $explicitPhone),
            'from_me' => $fromMe,
            'direction' => $fromMe ? 'outgoing' : 'incoming',
            'contact_name' => $contactName,
            'is_group' => $isGroup,
            'conversation_type' => $isGroup ? 'group' : 'individual',
            'group_name' => $groupName,
            'participant_jid' => $isGroup ? $participantJid : '',
            'participant_alternate_jid' => $isGroup ? $participantAlternateJid : '',
            'sender_jid' => $isGroup ? $participantJid : ($fromMe ? '' : $remoteJid),
            'sender_phone' => $isGroup
                ? $this->phoneNumber($participantJid, $participantAlternateJid, '')
                : ($fromMe ? '' : $this->phoneNumber($remoteJid, $alternateJid, $explicitPhone)),
            'sender_name' => $isGroup ? $senderName : (!$fromMe ? $contactName : ''),
            'provider_name' => $this->firstScalar([
                $root['provider_name'] ?? null,
                $root['provider'] ?? null,
                $data['provider_name'] ?? null,
                $data['provider'] ?? null,
            ]) ?: 'evolution',
            'provider_payload_id' => $this->firstScalar([
                $root['provider_payload_id'] ?? null,
                $root['providerPayloadId'] ?? null,
                $data['provider_payload_id'] ?? null,
                $data['providerPayloadId'] ?? null,
            ]),
            'delivery_error' => $this->firstScalar([
                $root['delivery_error'] ?? null,
                $data['delivery_error'] ?? null,
                $this->path($root, ['error', 'message']),
                $this->path($data, ['error', 'message']),
            ]),
            'delivery_error_code' => $this->firstScalar([
                $root['delivery_error_code'] ?? null,
                $root['provider_error_code'] ?? null,
                $data['delivery_error_code'] ?? null,
                $data['provider_error_code'] ?? null,
                $this->path($root, ['error', 'code']),
                $this->path($data, ['error', 'code']),
                $this->path($data, ['errors', 0, 'code']),
            ]),
            'timestamp' => $timestamp,
            'text' => $text,
            'message_type' => $messageType,
            'media_url' => $media['url'],
            'mime_type' => $media['mime_type'],
            'file_name' => $media['file_name'],
            'structured_content' => $structured,
            'context_message_id' => $replyToExternalId !== '' ? $replyToExternalId : null,
            'reply_to_external_message_id' => $replyToExternalId !== '' ? $replyToExternalId : null,
            'is_voice_note' => $messageType === 'audio' && (bool) ($structured['attachment']['is_voice_note'] ?? false),
            'raw_provider_type' => $this->firstScalar([
                $root['raw_provider_type'] ?? null,
                $data['raw_provider_type'] ?? null,
                $messageType,
            ]),
            'status' => $sourceStatus,
            'message_status' => $mappedStatus,
        ];
        $normalized['dedupe_key'] = $this->dedupeKey($normalized);

        return $normalized;
    }

    /** @see normalize() */
    public function normalize_payload($payload): array
    {
        return $this->normalize($payload);
    }

    /** @param array<string, mixed> $normalized */
    public function dedupe_key(array $normalized): string
    {
        return $this->dedupeKey($normalized);
    }

    /** @param array<string, mixed> $root @param array<string, mixed> $data */
    private function extractInstance(array $root, array $data): string
    {
        $instance = $root['instance'] ?? null;
        if (is_array($instance) || is_object($instance)) {
            $instance = $this->firstScalar([
                $this->path($this->toArray($instance), ['instanceName']),
                $this->path($this->toArray($instance), ['name']),
            ]);
        }

        return $this->firstScalar([
            $root['instance_name'] ?? null,
            $root['instanceName'] ?? null,
            $instance,
            $data['instance_name'] ?? null,
            $data['instanceName'] ?? null,
            is_scalar($data['instance'] ?? null) ? $data['instance'] : null,
            $this->path($this->toArray($data['instance'] ?? []), ['instanceName']),
            $this->path($this->toArray($data['instance'] ?? []), ['name']),
        ]);
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $data
     * @param array<string, mixed> $message
     * @return array{node:array<string,mixed>,node_type:string,url:?string,mime_type:?string,file_name:?string}
     */
    private function extractMedia(array $root, array $data, array $message): array
    {
        $nodes = [
            'image' => $this->toArray($message['imageMessage'] ?? $data['imageMessage'] ?? []),
            'audio' => $this->toArray($message['audioMessage'] ?? $data['audioMessage'] ?? []),
            'video' => $this->toArray($message['videoMessage'] ?? $data['videoMessage'] ?? []),
            'document' => $this->toArray($message['documentMessage'] ?? $data['documentMessage'] ?? []),
            'sticker' => $this->toArray($message['stickerMessage'] ?? $data['stickerMessage'] ?? []),
        ];
        $wrappedDocument = $this->toArray($message['documentWithCaptionMessage'] ?? []);
        if ($nodes['document'] === [] && $wrappedDocument !== []) {
            $nodes['document'] = $this->toArray($this->path($wrappedDocument, ['message', 'documentMessage']) ?? []);
        }

        $nodeType = '';
        $node = [];
        foreach ($nodes as $type => $candidate) {
            if ($candidate !== []) {
                $nodeType = $type;
                $node = $candidate;
                break;
            }
        }

        $url = $this->firstScalar([
            $root['media_url'] ?? null,
            $root['mediaUrl'] ?? null,
            is_scalar($root['media'] ?? null) ? $root['media'] : null,
            $this->path($this->toArray($root['media'] ?? []), ['url']),
            $data['media_url'] ?? null,
            $data['mediaUrl'] ?? null,
            is_scalar($data['media'] ?? null) ? $data['media'] : null,
            $this->path($this->toArray($data['media'] ?? []), ['url']),
            $node['url'] ?? null,
            $node['mediaUrl'] ?? null,
            $node['directPath'] ?? null,
        ]);
        $mimeType = $this->firstScalar([
            $root['mime_type'] ?? null,
            $root['mimetype'] ?? null,
            $this->path($this->toArray($root['media'] ?? []), ['mime_type']),
            $this->path($this->toArray($root['media'] ?? []), ['mimetype']),
            $data['mime_type'] ?? null,
            $data['mimetype'] ?? null,
            $this->path($this->toArray($data['media'] ?? []), ['mime_type']),
            $this->path($this->toArray($data['media'] ?? []), ['mimetype']),
            $node['mimetype'] ?? null,
            $node['mimeType'] ?? null,
        ]);
        $fileName = $this->firstScalar([
            $root['file_name'] ?? null,
            $root['fileName'] ?? null,
            $this->path($this->toArray($root['media'] ?? []), ['file_name']),
            $this->path($this->toArray($root['media'] ?? []), ['fileName']),
            $data['file_name'] ?? null,
            $data['fileName'] ?? null,
            $this->path($this->toArray($data['media'] ?? []), ['file_name']),
            $this->path($this->toArray($data['media'] ?? []), ['fileName']),
            $node['fileName'] ?? null,
            $node['title'] ?? null,
        ]);

        return [
            'node' => $node,
            'node_type' => $nodeType,
            'url' => $url !== '' ? $url : null,
            'mime_type' => $mimeType !== '' ? $mimeType : null,
            'file_name' => $fileName !== '' ? $fileName : null,
        ];
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $data
     * @param array<string, mixed> $message
     */
    private function extractMessageType(array $root, array $data, array $message, string $mediaType): string
    {
        if ($mediaType !== '') {
            return $mediaType;
        }

        $type = strtolower($this->firstScalar([
            $root['message_type'] ?? null,
            $root['content_type'] ?? null,
            $data['message_type'] ?? null,
            $data['messageType'] ?? null,
            $data['type'] ?? null,
        ]));

        if (str_contains($type, 'image')) {
            return 'image';
        }
        if (str_contains($type, 'audio') || str_contains($type, 'voice') || $type === 'ptt') {
            return 'audio';
        }
        if (str_contains($type, 'video')) {
            return 'video';
        }
        if (str_contains($type, 'document') || str_contains($type, 'file')) {
            return 'document';
        }
        if (str_contains($type, 'sticker')) {
            return 'sticker';
        }
        if ($type === 'location' || str_contains($type, 'location')) {
            return 'location';
        }
        if ($type === 'contact' || $type === 'contacts' || str_contains($type, 'contact')) {
            return 'contact';
        }
        if ($type === 'reaction' || str_contains($type, 'reaction')) {
            return 'reaction';
        }
        if ($type === 'interactive' || $type === 'button' || $type === 'list') {
            return 'interactive';
        }
        if ($type === 'template' || str_contains($type, 'template')) {
            return 'template';
        }
        if ($type === 'text' || str_contains($type, 'conversation') || str_contains($type, 'extendedtext')) {
            return 'text';
        }

        if (isset($message['conversation']) || isset($message['extendedTextMessage'])) {
            return 'text';
        }
        if (isset($message['videoMessage'])) return 'video';
        if (isset($message['stickerMessage'])) return 'sticker';
        if (isset($message['locationMessage'])) return 'location';
        if (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) return 'contact';
        if (isset($message['reactionMessage'])) return 'reaction';
        if (isset($message['buttonsResponseMessage']) || isset($message['listResponseMessage'])) return 'interactive';

        if (is_scalar($root['message'] ?? null) || is_scalar($data['message'] ?? null)) {
            return 'text';
        }

        return $type !== '' ? $type : 'unknown';
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $data
     * @param array<string, mixed> $message
     * @param array<string, mixed> $mediaNode
     */
    private function extractText(array $root, array $data, array $message, array $mediaNode): string
    {
        return $this->firstScalar([
            $root['text'] ?? null,
            $root['text_content'] ?? null,
            is_scalar($root['content'] ?? null) ? $root['content'] : null,
            is_scalar($root['message'] ?? null) ? $root['message'] : null,
            $data['text'] ?? null,
            $data['text_content'] ?? null,
            is_scalar($data['content'] ?? null) ? $data['content'] : null,
            is_scalar($data['message'] ?? null) ? $data['message'] : null,
            is_scalar($message['conversation'] ?? null) ? $message['conversation'] : null,
            $this->path($message, ['extendedTextMessage', 'text']),
            $this->path($message, ['buttonsResponseMessage', 'selectedDisplayText']),
            $this->path($message, ['listResponseMessage', 'title']),
            $this->path($message, ['reactionMessage', 'text']),
            $this->path($message, ['locationMessage', 'name']),
            $this->path($message, ['contactMessage', 'displayName']),
            $mediaNode['caption'] ?? null,
        ]);
    }

    /**
     * Keeps structured WhatsApp concepts available to the V2 projector while
     * limiting them to a small, browser-safe subset.
     *
     * @param array{node:array<string,mixed>,node_type:string,url:?string,mime_type:?string,file_name:?string} $media
     * @return array<string,mixed>
     */
    private function extractStructuredContent(array $root, array $data, array $message, string $messageType, array $media): array
    {
        foreach ([
            $this->toArray($root['structured_content'] ?? null),
            $this->toArray($data['structured_content'] ?? null),
        ] as $direct) {
            if ($direct !== []) return $direct;
        }

        if ($media['node'] !== []) {
            $node = $media['node'];
            return ['attachment' => [
                'kind' => $media['node_type'] !== '' ? $media['node_type'] : $messageType,
                'provider_media_id' => $this->firstScalar([$node['mediaKey'] ?? null, $node['fileSha256'] ?? null]),
                'mime_type' => $media['mime_type'],
                'file_name' => $media['file_name'],
                'caption' => $this->firstScalar([$node['caption'] ?? null]),
                'file_size' => is_numeric($node['fileLength'] ?? null) ? (int) $node['fileLength'] : null,
                'width' => is_numeric($node['width'] ?? null) ? (int) $node['width'] : null,
                'height' => is_numeric($node['height'] ?? null) ? (int) $node['height'] : null,
                'duration' => is_numeric($node['seconds'] ?? null) ? (int) $node['seconds'] : null,
                'is_voice_note' => $messageType === 'audio' && (bool) ($node['ptt'] ?? false),
            ]];
        }

        $location = $this->toArray($message['locationMessage'] ?? $data['locationMessage'] ?? $root['location'] ?? []);
        if ($location !== []) {
            return ['location' => [
                'latitude' => $location['degreesLatitude'] ?? $location['latitude'] ?? null,
                'longitude' => $location['degreesLongitude'] ?? $location['longitude'] ?? null,
                'name' => $this->firstScalar([$location['name'] ?? null]),
                'address' => $this->firstScalar([$location['address'] ?? null]),
            ]];
        }

        $contact = $this->toArray($message['contactMessage'] ?? $data['contactMessage'] ?? []);
        $contactList = $message['contactsArrayMessage']['contacts'] ?? $message['contacts'] ?? $data['contacts'] ?? [];
        if ($contact !== [] || is_array($contactList) && $contactList !== []) {
            $items = [];
            foreach ($contact !== [] ? [$contact] : $contactList as $item) {
                $item = $this->toArray($item);
                if ($item === []) continue;
                $items[] = [
                    'display_name' => $this->firstScalar([$item['displayName'] ?? null, $item['display_name'] ?? null]),
                    'phones' => $this->vcardValues((string) ($item['vcard'] ?? ''), 'TEL'),
                    'emails' => $this->vcardValues((string) ($item['vcard'] ?? ''), 'EMAIL'),
                    'organization' => $this->firstScalar([$item['organization'] ?? null]),
                ];
            }
            return ['contact' => ['contacts' => array_slice($items, 0, 10)]];
        }

        $interactive = $this->toArray($message['buttonsResponseMessage'] ?? $message['listResponseMessage'] ?? $data['interactive'] ?? []);
        if ($interactive !== []) {
            return ['interactive' => [
                'kind' => isset($message['listResponseMessage']) ? 'list' : 'button',
                'id' => $this->firstScalar([$interactive['selectedButtonId'] ?? null, $interactive['singleSelectReply']['selectedRowId'] ?? null]),
                'label' => $this->firstScalar([$interactive['selectedDisplayText'] ?? null, $interactive['singleSelectReply']['title'] ?? null]),
                'description' => $this->firstScalar([$interactive['description'] ?? null]),
                'context' => null,
            ]];
        }

        $reaction = $this->toArray($message['reactionMessage'] ?? $data['reaction'] ?? []);
        if ($reaction !== []) {
            return ['reaction' => [
                'emoji' => $this->firstScalar([$reaction['text'] ?? null, $reaction['emoji'] ?? null]),
                'message_id' => $this->firstScalar([$reaction['key']['id'] ?? null, $reaction['messageId'] ?? null]),
                'removed' => $this->firstScalar([$reaction['text'] ?? null, $reaction['emoji'] ?? null]) === '',
            ]];
        }

        return [];
    }

    private function extractReplyToExternalId(array $root, array $data, array $message): string
    {
        return $this->firstScalar([
            $root['reply_to_external_message_id'] ?? null,
            $root['context_message_id'] ?? null,
            $data['reply_to_external_message_id'] ?? null,
            $data['context_message_id'] ?? null,
            $this->path($message, ['extendedTextMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['imageMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['audioMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['videoMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['documentMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['stickerMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['locationMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['contactMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['contactsArrayMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['buttonsResponseMessage', 'contextInfo', 'stanzaId']),
            $this->path($message, ['listResponseMessage', 'contextInfo', 'stanzaId']),
        ]);
    }

    /** @return array<int,string> */
    private function vcardValues(string $vcard, string $field): array
    {
        if ($vcard === '') return [];
        $values = [];
        foreach (preg_split('/\r?\n/', $vcard) ?: [] as $line) {
            if (!str_starts_with(strtoupper($line), $field . ':') && !str_starts_with(strtoupper($line), $field . ';')) continue;
            $value = trim((string) substr($line, strpos($line, ':') + 1));
            if ($value !== '') $values[] = substr($value, 0, $field === 'TEL' ? 64 : 191);
            if (count($values) >= 10) break;
        }
        return $values;
    }

    /** @param array<string, mixed> $normalized */
    private function dedupeKey(array $normalized): string
    {
        $instance = (string) ($normalized['instance_name'] ?? $normalized['instance'] ?? '');
        $externalId = (string) ($normalized['external_message_id'] ?? '');
        if ($externalId !== '') {
            return 'message:' . hash('sha256', $instance . "\0" . $externalId);
        }

        $canonical = [
            'event' => (string) ($normalized['event'] ?? ''),
            'instance' => $instance,
            'remote_jid' => (string) ($normalized['remote_jid'] ?? ''),
            'sender_jid' => (string) ($normalized['sender_jid'] ?? ''),
            'from_me' => (bool) ($normalized['from_me'] ?? false),
            'timestamp' => $normalized['timestamp'] ?? null,
            'message_type' => (string) ($normalized['message_type'] ?? ''),
            'text' => (string) ($normalized['text'] ?? ''),
            'media_url' => (string) ($normalized['media_url'] ?? ''),
            'mime_type' => (string) ($normalized['mime_type'] ?? ''),
            'status' => (string) ($normalized['status'] ?? ''),
        ];
        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'fallback:' . hash('sha256', is_string($encoded) ? $encoded : serialize($canonical));
    }

    private function normalizeEvent(string $event): string
    {
        $event = strtolower(trim($event));
        $event = str_replace(['_', '-'], '.', $event);
        $event = (string) preg_replace('/\.+/', '.', $event);

        return trim($event, '.');
    }

    private function normalizeStatus(string $status): ?string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return null;
        }

        return (string) preg_replace('/[\s.-]+/', '_', $status);
    }

    private function mapMessageStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $map = [
            'pending' => 'sent',
            'server_ack' => 'sent',
            'sent' => 'sent',
            'delivery_ack' => 'delivered',
            'delivered' => 'delivered',
            'read' => 'read',
            'played' => 'read',
            'error' => 'failed',
            'failed' => 'failed',
        ];

        return $map[$status] ?? $status;
    }

    /** @param mixed $value @return int|string|null */
    private function normalizeTimestamp($value)
    {
        if (is_array($value)) {
            $value = $value['low'] ?? $value['seconds'] ?? null;
        } elseif (is_object($value)) {
            $object = get_object_vars($value);
            $value = $object['low'] ?? $object['seconds'] ?? null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 20000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return $timestamp;
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    private function isGroupJid(string $remoteJid): bool
    {
        return str_ends_with(strtolower(trim($remoteJid)), '@g.us');
    }

    private function phoneFromJid(string $remoteJid): string
    {
        $localPart = explode('@', $remoteJid, 2)[0];

        return (string) preg_replace('/\D+/', '', $localPart);
    }

    private function phoneNumber(string $remoteJid, string $alternateJid, string $explicitPhone): string
    {
        if ($this->isPhoneJid($alternateJid)) {
            return $this->phoneFromJid($alternateJid);
        }

        $explicitDigits = (string) preg_replace('/\D+/', '', $explicitPhone);
        if ($explicitDigits !== '') {
            return $explicitDigits;
        }

        if (str_ends_with(strtolower(trim($remoteJid)), '@lid')) {
            return '';
        }

        return $this->phoneFromJid($remoteJid);
    }

    private function isPhoneJid(string $jid): bool
    {
        $jid = strtolower(trim($jid));

        return str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us');
    }

    /** @param mixed $value */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'sim'], true);
    }

    /** @param array<int, mixed> $values @return mixed */
    private function firstValue(array $values)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $values */
    private function firstScalar(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** @param mixed $payload @return array<string, mixed> */
    private function decode($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_object($payload)) {
            return get_object_vars($payload);
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param mixed $value @return array<string, mixed> */
    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    /** @param array<string, mixed> $data @param array<int, int|string> $path @return mixed */
    private function path(array $data, array $path)
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param array<string, mixed> $array */
    private function isList(array $array): bool
    {
        return $array !== [] && array_keys($array) === range(0, count($array) - 1);
    }

    /** @param array<string, mixed> $root */
    private function hasEnvelopeFields(array $root): bool
    {
        return isset($root['event']) || isset($root['instance']) || isset($root['data']);
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        $result = [
            'event' => '',
            'instance' => '',
            'instance_name' => '',
            'external_event_id' => null,
            'external_message_id' => null,
            'remote_jid' => '',
            'alternate_remote_jid' => '',
            'phone_number' => '',
            'from_me' => false,
            'direction' => 'incoming',
            'contact_name' => '',
            'is_group' => false,
            'conversation_type' => 'individual',
            'group_name' => '',
            'participant_jid' => '',
            'participant_alternate_jid' => '',
            'sender_jid' => '',
            'sender_phone' => '',
            'sender_name' => '',
            'provider_name' => 'evolution',
            'timestamp' => null,
            'text' => '',
            'message_type' => 'unknown',
            'media_url' => null,
            'mime_type' => null,
            'file_name' => null,
            'status' => null,
            'message_status' => null,
        ];
        $result['dedupe_key'] = $this->dedupeKey($result);

        return $result;
    }
}
