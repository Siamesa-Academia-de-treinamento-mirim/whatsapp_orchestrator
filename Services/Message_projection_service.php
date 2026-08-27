<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

/**
 * Projects the persisted message read model into the provider-neutral Inbox 3
 * Message Contract V2. It deliberately accepts plain arrays so the contract
 * can be tested without booting Rise or a database connection.
 */
final class Message_projection_service
{
    private const TYPES = [
        'text', 'gallery', 'image', 'audio', 'voice', 'video', 'document',
        'sticker', 'location', 'contact', 'template', 'interactive',
        'reaction', 'internal_note', 'activity', 'unsupported',
    ];

    /** @return array<string,mixed> */
    public function project(array $row, ?array $replyTarget = null): array
    {
        $raw = $this->decode($row['raw_payload'] ?? null);
        $normalized = $this->arrayValue($raw['normalized'] ?? $raw['_normalized'] ?? null);
        $structured = $this->structuredContent($row, $raw, $normalized);
        $storedType = strtolower(trim((string) ($row['message_type'] ?? 'text')));
        $type = $this->canonicalType($storedType, $structured, $normalized);
        $provider = $this->provider((string) ($row['provider_name'] ?? $normalized['provider_name'] ?? 'evolution'));
        $text = $this->bounded((string) ($row['text_content'] ?? $normalized['text'] ?? ''), 10000);
        $caption = $this->bounded((string) ($row['caption'] ?? $this->attachmentValue($structured, 'caption') ?? ''), 4096);
        $mediaUrl = $this->safeUrl((string) ($row['media_url'] ?? $this->attachmentValue($structured, 'url') ?? ''));
        $attachment = $this->attachment($row, $type, $structured, $mediaUrl);
        $location = $this->location($structured);
        $contact = $this->contact($structured);
        $template = $this->objectOrNull($structured['template'] ?? null);
        $interactive = $this->objectOrNull($structured['interactive'] ?? null);
        $reaction = $this->reaction($structured);
        $safeTypeHint = $this->safeTypeHint(
            $normalized['raw_provider_type'] ?? $normalized['message_type'] ?? $storedType,
            $type
        );

        return [
            'contract_version' => 2,
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'conversation_id' => isset($row['conversation_id']) ? (int) $row['conversation_id'] : 0,
            'instance_id' => isset($row['instance_id']) ? (int) $row['instance_id'] : 0,
            'provider' => $provider,
            'provider_message_id' => $this->nullableString($row['external_message_id'] ?? null, 191),
            'client_message_id' => $this->nullableString($row['client_message_id'] ?? null, 191),
            'direction' => $this->direction($row),
            'type' => $type,
            'status' => $this->status((string) ($row['status'] ?? 'received')),
            'sender' => [
                'kind' => $this->senderKind($row, $type),
                'user_id' => !empty($row['sender_user_id']) ? (int) $row['sender_user_id'] : null,
                'contact_id' => !empty($row['sender_contact_id']) ? (int) $row['sender_contact_id'] : null,
                'jid' => $this->nullableString($row['sender_jid'] ?? null, 191),
                'phone' => $this->nullableString($row['sender_phone'] ?? null, 64),
                'name' => $this->nullableString($row['sender_name'] ?? null, 191),
            ],
            'content' => [
                'text' => $text,
                'caption' => $caption,
                'attachments' => $attachment === null ? [] : [$attachment],
                'location' => $location,
                'contact' => $contact,
                'template' => $template,
                'interactive' => $interactive,
                'reaction' => $reaction,
            ],
            'reply_to' => $this->replyTo($row, $raw, $normalized, $replyTarget),
            'reactions' => $this->reactions($row, $structured),
            'timestamps' => [
                'created_at' => $this->isoDate($row['created_at'] ?? null),
                'sent_at' => $this->isoDate($row['sent_at'] ?? null),
                'delivered_at' => $this->isoDate($row['delivered_at'] ?? null),
                'read_at' => $this->isoDate($row['read_at'] ?? null),
                'failed_at' => $this->isoDate($row['failed_at'] ?? null),
            ],
            'error' => $this->error($row, $raw, $normalized),
            'actions' => [],
            'metadata' => [
                'provider_payload_id' => $this->nullableString($row['provider_payload_id'] ?? null, 191),
                'safe_type_hint' => $type === 'unsupported' ? $safeTypeHint : null,
                'raw_provider_type' => $type === 'unsupported' ? $safeTypeHint : null,
                'is_voice_note' => $type === 'voice' || (bool) ($this->attachmentValue($structured, 'is_voice_note') ?? false),
                'send_state' => $this->sendState($raw),
            ],
            // Top-level aliases are intentionally emitted with V2 so the
            // current monolithic frontend can coexist with new consumers.
            'message_type' => $storedType === 'voice' ? 'audio' : ($storedType !== '' ? $storedType : $type),
            'text_content' => $text,
            'media_url' => $mediaUrl,
            'mime_type' => $this->nullableString($row['mime_type'] ?? $this->attachmentValue($structured, 'mime_type') ?? null, 191),
            'caption' => $caption,
            'file_name' => $this->nullableString($row['file_name'] ?? $this->attachmentValue($structured, 'file_name') ?? null, 255) ?? '',
            'file_size' => (int) ($row['file_size'] ?? $this->attachmentValue($structured, 'file_size') ?? 0),
            'external_message_id' => $row['external_message_id'] ?? null,
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'sender_phone' => (string) ($row['sender_phone'] ?? ''),
            'is_internal_note' => !empty($row['is_internal_note']) || $type === 'internal_note',
        ];
    }

    private function canonicalType(string $storedType, array $structured, array $normalized): string
    {
        $type = $storedType;
        if ($type === 'note') $type = 'internal_note';
        if ($type === 'audio' && (bool) ($this->attachmentValue($structured, 'is_voice_note') ?? $normalized['is_voice_note'] ?? false)) {
            $type = 'voice';
        }
        if ($type === '') $type = strtolower(trim((string) ($normalized['message_type'] ?? 'text')));

        return in_array($type, self::TYPES, true) ? $type : 'unsupported';
    }

    /** @return array<string,mixed> */
    private function structuredContent(array $row, array $raw, array $normalized): array
    {
        foreach ([
            $this->arrayValue($row['structured_content'] ?? null),
            $this->arrayValue($normalized['structured_content'] ?? null),
            $this->arrayValue($raw['structured_content'] ?? null),
        ] as $candidate) {
            if ($candidate !== []) {
                if (is_array($candidate['template'] ?? null)) return ['template' => $this->normalizeTemplateNode($candidate['template'])];
                return $candidate;
            }
        }

        $type = strtolower(trim((string) ($row['message_type'] ?? $normalized['message_type'] ?? '')));
        $message = $this->arrayValue($raw['data']['message'] ?? $raw['message'] ?? null);
        if ($message === []) return [];
        if (in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            $node = $this->arrayValue($message[$type . 'Message'] ?? $message[$type] ?? null);
            if ($node !== []) return ['attachment' => [
                'kind' => $type,
                'mime_type' => $node['mimetype'] ?? $node['mime_type'] ?? null,
                'file_name' => $node['fileName'] ?? $node['filename'] ?? null,
                'caption' => $node['caption'] ?? null,
                'file_size' => $node['fileLength'] ?? null,
                'width' => $node['width'] ?? null,
                'height' => $node['height'] ?? null,
                'duration' => $node['seconds'] ?? null,
                'is_voice_note' => $type === 'audio' && !empty($node['ptt']),
                'provider_media_id' => $node['id'] ?? null,
            ]];
        }
        if ($type === 'location') {
            $node = $this->arrayValue($message['locationMessage'] ?? $message['location'] ?? null);
            if ($node !== []) return ['location' => [
                'latitude' => $node['degreesLatitude'] ?? $node['latitude'] ?? null,
                'longitude' => $node['degreesLongitude'] ?? $node['longitude'] ?? null,
                'name' => $node['name'] ?? null,
                'address' => $node['address'] ?? null,
            ]];
        }
        if ($type === 'contact') {
            $node = $this->arrayValue($message['contactMessage'] ?? null);
            if ($node !== []) return ['contact' => [
                'display_name' => $node['displayName'] ?? null,
                'phones' => [],
                'emails' => [],
                'organization' => null,
            ]];
        }
        if ($type === 'reaction') {
            $node = $this->arrayValue($message['reactionMessage'] ?? $message['reaction'] ?? null);
            if ($node !== []) return ['reaction' => [
                'emoji' => $node['text'] ?? $node['emoji'] ?? null,
                'message_id' => $node['key']['id'] ?? $node['messageId'] ?? null,
            ]];
        }
        if ($type === 'interactive') {
            $node = $this->arrayValue($message['buttonsResponseMessage'] ?? $message['listResponseMessage'] ?? $message['interactive'] ?? null);
            if ($node !== []) return ['interactive' => [
                'kind' => isset($message['listResponseMessage']) ? 'list' : 'button',
                'id' => $node['selectedButtonId'] ?? $node['singleSelectReply']['selectedRowId'] ?? $node['button_reply']['id'] ?? null,
                'label' => $node['selectedDisplayText'] ?? $node['singleSelectReply']['title'] ?? $node['button_reply']['title'] ?? null,
                'description' => $node['description'] ?? $node['list_reply']['description'] ?? null,
            ]];
        }
        if ($type === 'template') {
            $template = $this->arrayValue($raw['template'] ?? $message['template'] ?? null);
            if ($template !== []) {
                $components = $this->arrayValue($template['components'] ?? []);
                return ['template' => $this->normalizeTemplateNode($template)];
            }
        }
        return [];
    }

    /** @return array<string,mixed>|null */
    private function attachment(array $row, string $type, array $structured, ?string $mediaUrl): ?array
    {
        if (!in_array($type, ['image', 'audio', 'voice', 'video', 'document', 'sticker'], true)
            && empty($row['media_id']) && $mediaUrl === null) {
            return null;
        }

        $source = $this->arrayValue($structured['attachment'] ?? null);
        $kind = $type === 'voice' ? 'audio' : $type;
        if (!in_array($kind, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            $kind = (string) ($source['kind'] ?? 'document');
        }

        return [
            'id' => !empty($row['media_id']) ? (int) $row['media_id'] : null,
            'kind' => $kind,
            'url' => $mediaUrl,
            'mime_type' => $this->nullableString($row['mime_type'] ?? $source['mime_type'] ?? null, 191),
            'file_name' => $this->nullableString($row['file_name'] ?? $source['file_name'] ?? null, 255),
            'file_size' => $this->positiveInt($row['file_size'] ?? $source['file_size'] ?? null),
            'width' => $this->positiveInt($source['width'] ?? null),
            'height' => $this->positiveInt($source['height'] ?? null),
            'duration_ms' => $this->durationMs($source),
            'is_voice_note' => $type === 'voice' || (bool) ($source['is_voice_note'] ?? false),
            'provider_media_id' => $this->nullableString($source['provider_media_id'] ?? $source['id'] ?? null, 191),
        ];
    }

    /** @return array<string,mixed>|null */
    private function location(array $structured): ?array
    {
        $location = $this->arrayValue($structured['location'] ?? null);
        if ($location === []) return null;

        $latitude = $this->coordinate($location['latitude'] ?? $location['lat'] ?? null, -90, 90);
        $longitude = $this->coordinate($location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null, -180, 180);
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $this->nullableString($location['name'] ?? null, 191),
            'address' => $this->nullableString($location['address'] ?? null, 500),
        ];
    }

    /** @return array<string,mixed>|null */
    private function contact(array $structured): ?array
    {
        $contact = $this->arrayValue($structured['contact'] ?? null);
        if ($contact === []) return null;
        $contacts = [];
        foreach ((array) ($contact['contacts'] ?? []) as $item) {
            $mapped = $this->contactItem($this->arrayValue($item));
            if ($mapped !== null) $contacts[] = $mapped;
        }
        $first = $this->contactItem($contact);
        if ($first !== null && $contacts === []) $contacts[] = $first;
        if ($first === null && $contacts !== []) $first = $contacts[0];
        if ($first === null) return null;
        $first['contacts'] = $contacts;
        return $first;
    }

    /** @return array<string,mixed>|null */
    private function contactItem(array $contact): ?array
    {
        $name = $this->nullableString($contact['display_name'] ?? $contact['name'] ?? $contact['formatted_name'] ?? null, 191);
        $phones = $this->stringList($contact['phones'] ?? $contact['phone'] ?? [], 64, 10);
        $emails = $this->stringList($contact['emails'] ?? $contact['email'] ?? [], 191, 10);
        $organization = $this->nullableString($contact['organization'] ?? null, 191);
        if ($name === null && $phones === [] && $emails === [] && $organization === null) return null;
        return [
            'display_name' => $name,
            'phones' => $phones,
            'emails' => $emails,
            'organization' => $organization,
        ];
    }

    /** @return array<string,mixed>|null */
    private function reaction(array $structured): ?array
    {
        $reaction = $this->arrayValue($structured['reaction'] ?? null);
        if ($reaction === []) return null;
        return [
            'emoji' => $this->nullableString($reaction['emoji'] ?? null, 32),
            'removed' => (bool) ($reaction['removed'] ?? false),
            'target_provider_message_id' => $this->nullableString($reaction['target_provider_message_id'] ?? $reaction['message_id'] ?? null, 191),
            'target_message_id' => !empty($reaction['target_message_id']) ? (int) $reaction['target_message_id'] : null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function reactions(array $row, array $structured): array
    {
        $reactions = $structured['reactions'] ?? $row['reactions'] ?? [];
        if (!is_array($reactions)) return [];
        $result = [];
        foreach ($reactions as $reaction) {
            if (!is_array($reaction)) continue;
            $emoji = $this->nullableString($reaction['emoji'] ?? null, 32);
            if ($emoji === null) continue;
            $result[] = [
                'emoji' => $emoji,
                'count' => max(1, (int) ($reaction['count'] ?? 1)),
                'reacted_by_me' => (bool) ($reaction['reacted_by_me'] ?? false),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function replyTo(array $row, array $raw, array $normalized, ?array $replyTarget): ?array
    {
        $externalId = trim((string) ($row['reply_to_external_message_id'] ?? $normalized['reply_to_external_message_id'] ?? $normalized['context_message_id'] ?? ''));
        if ($externalId === '') return null;
        $resolved = is_array($replyTarget) && !empty($replyTarget['id']);
        $localId = $raw['send']['reply_to_local_message_id'] ?? $raw['media_engine']['reply_to_local_message_id'] ?? null;
        if (!is_numeric($localId) || (int) $localId < 1) $localId = $resolved ? (int) $replyTarget['id'] : null;
        return [
            'provider_message_id' => $externalId,
            'message_id' => $resolved ? (int) $replyTarget['id'] : null,
            'local_message_id' => $localId !== null ? (int) $localId : null,
            'type' => $resolved ? $this->canonicalType((string) ($replyTarget['message_type'] ?? 'text'), [], []) : null,
            'author' => $resolved ? $this->nullableString($replyTarget['sender_name'] ?? null, 191) : null,
            'preview' => $resolved ? $this->bounded((string) ($replyTarget['text_content'] ?? $replyTarget['caption'] ?? ''), 500) : null,
            'resolved' => $resolved,
        ];
    }

    /** @return array<string,mixed>|null */
    private function error(array $row, array $raw, array $normalized): ?array
    {
        if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'failed' && trim((string) ($row['delivery_error'] ?? '')) === '') return null;
        $message = trim((string) ($row['delivery_error'] ?? $normalized['delivery_error'] ?? ''));
        return [
            'code' => $this->errorCode($normalized['error_code'] ?? $raw['error_code'] ?? $raw['send']['error_code'] ?? $raw['send']['provider_error_code'] ?? $raw['media_engine']['error_code'] ?? $raw['media_engine']['provider_error_code'] ?? null),
            'message' => $this->bounded($message !== '' ? $message : 'Falha reportada pelo provedor.', 500),
            'retryable' => $this->sendState($raw) === 'retryable_failure',
            'suggested_action' => $this->sendState($raw) === 'ambiguous_failure' ? 'verify_provider_status' : null,
        ];
    }

    private function sendState(array $raw): ?string
    {
        $state = strtolower(trim((string) ($raw['send']['idempotency_state'] ?? $raw['media_engine']['idempotency_state'] ?? '')));
        return in_array($state, ['awaiting_provider', 'idempotent_success', 'retryable_failure', 'ambiguous_failure', 'rejected'], true) ? $state : null;
    }

    private function errorCode($value): string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^[A-Z0-9_.:-]{1,64}$/', $value) ? $value : 'PROVIDER_DELIVERY_FAILED';
    }

    private function senderKind(array $row, string $type): string
    {
        if ($type === 'internal_note' || !empty($row['is_internal_note'])) return 'user';
        if (!empty($row['sender_user_id']) || strtolower((string) ($row['direction'] ?? '')) === 'outgoing') return 'user';
        return 'contact';
    }

    private function direction(array $row): string
    {
        $direction = strtolower(trim((string) ($row['direction'] ?? 'incoming')));
        return in_array($direction, ['incoming', 'outgoing', 'internal'], true) ? $direction : 'incoming';
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['received', 'sending', 'sent', 'delivered', 'read', 'failed'], true) ? $status : 'received';
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return in_array($provider, ['evolution', 'meta_cloud'], true) ? $provider : 'unknown';
    }

    private function safeTypeHint($value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?: '';
        $value = trim(substr($value, 0, 64), '_');
        return $value !== '' ? $value : $fallback;
    }

    private function attachmentValue(array $structured, string $key)
    {
        $attachment = $this->arrayValue($structured['attachment'] ?? null);
        return $attachment[$key] ?? null;
    }

    /** @return array<string,mixed> */
    private function normalizeTemplateNode(array $template): array
    {
        $components = $this->arrayValue($template['components'] ?? []);
        return [
            'name' => $template['name'] ?? null,
            'language' => is_array($template['language'] ?? null) ? ($template['language']['code'] ?? null) : ($template['language'] ?? null),
            'category' => $template['category'] ?? null,
            'header' => $this->templatePart($template['resolved_header'] ?? $template['header'] ?? null),
            'body' => $this->templatePart($template['resolved_body'] ?? $template['body'] ?? null),
            'footer' => $this->templatePart($template['resolved_footer'] ?? $template['footer'] ?? null),
            'header_definition' => $this->templatePart($template['header_definition'] ?? $template['header'] ?? null),
            'body_definition' => $this->templatePart($template['body_definition'] ?? $template['body'] ?? null),
            'footer_definition' => $this->templatePart($template['footer_definition'] ?? $template['footer'] ?? null),
            'definitions' => [
                'header' => $this->templatePart($template['header_definition'] ?? $template['header'] ?? null),
                'body' => $this->templatePart($template['body_definition'] ?? $template['body'] ?? null),
                'footer' => $this->templatePart($template['footer_definition'] ?? $template['footer'] ?? null),
            ],
            'resolved_parameters' => $this->renderableTemplateParameters($template['resolved_parameters'] ?? $template['parameters'] ?? []),
            'components' => $this->renderableTemplateComponents($components),
            'buttons' => $this->displayTemplateButtons($template['buttons'] ?? []),
            'media_reference' => $this->templateMediaReference($template['media_reference'] ?? null),
        ];
    }

    private function templatePart($value): ?string
    {
        if (is_array($value)) $value = $value['text'] ?? $value['value'] ?? $value['title'] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? $this->bounded((string) $value, 4096) : null;
    }

    /** @return array<string,mixed>|null */
    private function templateMediaReference($value): ?array
    {
        if (!is_array($value)) return null;
        $localId = (int) ($value['local_media_id'] ?? 0);
        if ($localId < 1) return null;
        $kind = $this->nullableString($value['kind'] ?? null, 32) ?? 'document';
        return ['kind' => $kind, 'local_media_id' => $localId, 'url' => '/chatwoot_plugin/api/media/' . $localId];
    }

    /** @return array<int,string> */
    private function renderableTemplateParameters($value): array
    {
        $result = [];
        $walk = function ($item) use (&$walk, &$result): void {
            if (is_array($item)) {
                foreach (['text', 'value', 'title', 'label', 'name'] as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                        $result[] = $this->bounded((string) $item[$key], 4096);
                        return;
                    }
                }
                foreach ($item as $child) $walk($child);
                return;
            }
            if (is_scalar($item) && trim((string) $item) !== '') $result[] = $this->bounded((string) $item, 4096);
        };
        $walk($value);
        return array_values(array_unique($result));
    }

    /** @return array<int,array<string,mixed>> */
    private function renderableTemplateComponents(array $components): array
    {
        $result = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                $text = $this->templatePart($component);
                if ($text !== null) $result[] = ['type' => 'text', 'text' => $text];
                continue;
            }
            $result[] = [
                'type' => $this->nullableString($component['type'] ?? null, 32),
                'text' => $this->templatePart($component['text'] ?? $component['value'] ?? $component['title'] ?? null),
            ];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function displayTemplateButtons($buttons): array
    {
        $result = [];
        foreach ((array) $buttons as $index => $button) {
            if (is_scalar($button)) {
                $text = $this->nullableString($button, 255);
                if ($text !== null) $result[] = ['index' => (int) $index, 'type' => 'unknown', 'text' => $text];
                continue;
            }
            if (!is_array($button)) continue;
            $text = $this->nullableString($button['text'] ?? $button['title'] ?? $button['label'] ?? null, 255);
            if ($text === null) continue;
            $result[] = [
                'index' => (int) ($button['index'] ?? $index),
                'type' => $this->nullableString($button['type'] ?? null, 32) ?? 'unknown',
                'text' => $text,
            ];
        }
        return $result;
    }

    private function durationMs(array $source): ?int
    {
        if (isset($source['duration_ms']) && is_numeric($source['duration_ms'])) return max(0, (int) $source['duration_ms']);
        if (isset($source['duration']) && is_numeric($source['duration'])) return max(0, (int) round((float) $source['duration'] * 1000));
        if (isset($source['seconds']) && is_numeric($source['seconds'])) return max(0, (int) $source['seconds'] * 1000);
        return null;
    }

    private function coordinate($value, float $minimum, float $maximum): ?float
    {
        if (!is_numeric($value)) return null;
        $value = (float) $value;
        return $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function positiveInt($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<int,string> */
    private function stringList($value, int $limit, int $max): array
    {
        if (!is_array($value)) $value = [$value];
        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) $item = $item['value'] ?? $item['phone'] ?? $item['email'] ?? '';
            $item = $this->nullableString($item, $limit);
            if ($item !== null) $result[] = $item;
            if (count($result) >= $max) break;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function arrayValue($value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed>|null */
    private function objectOrNull($value): ?array
    {
        $value = $this->arrayValue($value);
        return $value === [] ? null : $value;
    }

    private function nullableString($value, int $limit): ?string
    {
        if (!is_scalar($value)) return null;
        $value = $this->bounded(trim((string) $value), $limit);
        return $value === '' ? null : $value;
    }

    private function bounded(string $value, int $limit): string
    {
        $value = str_replace("\0", '', $value);
        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private function safeUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 4096) return null;
        if (str_starts_with($value, '/')) return $value;
        if (filter_var($value, FILTER_VALIDATE_URL) === false) return null;
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        return $value;
    }

    private function isoDate($value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
