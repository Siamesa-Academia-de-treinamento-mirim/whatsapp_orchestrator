<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use InvalidArgumentException;

/** Converts Meta Cloud API webhook envelopes into the provider-neutral event shape. */
class Meta_webhook_normalizer
{
    /** @return array<int,array<string,mixed>> */
    public function expand(array $payload, string $instanceIdentifier): array
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            throw new InvalidArgumentException('Objeto de webhook Meta nao suportado.');
        }
        $events = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (!is_array($change) || (string) ($change['field'] ?? '') !== 'messages') continue;
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
                $contacts = $this->contactMap((array) ($value['contacts'] ?? []));
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (!is_array($message)) continue;
                    $event = $this->messageEvent($message, $contacts, $instanceIdentifier, $phoneNumberId);
                    if ($event) $events[] = $event;
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (!is_array($status)) continue;
                    $event = $this->statusEvent($status, $instanceIdentifier, $phoneNumberId);
                    if ($event) $events[] = $event;
                }
            }
        }
        return $events;
    }

    public function phoneNumberId(array $payload): string
    {
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (($entry['changes'] ?? [])) as $change) {
                $id = trim((string) ($change['value']['metadata']['phone_number_id'] ?? ''));
                if ($id !== '') return $id;
            }
        }
        return '';
    }

    /** @return array<string,string> */
    private function contactMap(array $contacts): array
    {
        $map = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) continue;
            $waId = preg_replace('/\D+/', '', (string) ($contact['wa_id'] ?? '')) ?: '';
            $name = trim((string) ($contact['profile']['name'] ?? ''));
            if ($waId !== '' && $name !== '') $map[$waId] = mb_substr($name, 0, 191);
        }
        return $map;
    }

    /** @return array<string,mixed>|null */
    private function messageEvent(array $message, array $contacts, string $instance, string $phoneNumberId): ?array
    {
        $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?: '';
        $id = trim((string) ($message['id'] ?? ''));
        if ($from === '' || $id === '') return null;
        $type = strtolower(trim((string) ($message['type'] ?? 'text')));
        $text = $this->messageText($message, $type);
        $media = is_array($message[$type] ?? null) ? $message[$type] : [];
        $mediaId = trim((string) ($media['id'] ?? ''));
        $structured = $this->structuredContent($message, $type);
        if ($type === 'reaction' && is_array($structured['reaction'] ?? null)) {
            $structured['reaction']['reactor_key'] = $from;
            $structured['reaction']['provider_event_id'] = $id;
            $structured['reaction']['from_me'] = false;
            $structured['reaction']['provider_timestamp'] = isset($message['timestamp']) && is_numeric($message['timestamp'])
                ? (string) $message['timestamp']
                : null;
        }
        $contextMessageId = trim((string) ($message['context']['id'] ?? ''));
        return [
            'event' => 'messages.upsert',
            'instance' => $instance,
            'instance_name' => $instance,
            'external_event_id' => $id,
            'external_message_id' => $id,
            'remote_jid' => $from . '@s.whatsapp.net',
            'phone_number' => $from,
            'from_me' => false,
            'contact_name' => (string) ($contacts[$from] ?? ''),
            'sender_name' => (string) ($contacts[$from] ?? ''),
            'timestamp' => isset($message['timestamp']) && is_numeric($message['timestamp'])
                ? (int) $message['timestamp']
                : null,
            'message_type' => $this->messageType($type),
            'text' => $text,
            'mime_type' => trim((string) ($media['mime_type'] ?? '')) ?: null,
            'file_name' => trim((string) ($media['filename'] ?? '')) ?: null,
            'provider_name' => 'meta_cloud',
            'provider_payload_id' => $mediaId !== '' ? $mediaId : $id,
            'meta_phone_number_id' => $phoneNumberId,
            'provider_message_type' => $type,
            'is_customer_message' => $this->qualifiesCustomerMessage($type),
            'context_message_id' => $contextMessageId !== '' ? $contextMessageId : null,
            'reply_to_external_message_id' => $contextMessageId !== '' ? $contextMessageId : null,
            'structured_content' => $structured,
            'is_voice_note' => (bool) ($structured['attachment']['is_voice_note'] ?? false),
            'raw_provider_type' => $type,
        ];
    }

    /** @return array<string,mixed>|null */
    private function statusEvent(array $status, string $instance, string $phoneNumberId): ?array
    {
        $id = trim((string) ($status['id'] ?? ''));
        if ($id === '') return null;
        $state = strtolower(trim((string) ($status['status'] ?? '')));
        $mapped = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed'][$state] ?? '';
        if ($mapped === '') return null;
        $recipient = preg_replace('/\D+/', '', (string) ($status['recipient_id'] ?? '')) ?: '';
        $error = '';
        $errorCode = '';
        if (!empty($status['errors'][0]) && is_array($status['errors'][0])) {
            $error = trim((string) ($status['errors'][0]['message'] ?? $status['errors'][0]['title'] ?? ''));
            $errorCode = trim((string) ($status['errors'][0]['code'] ?? $status['errors'][0]['error_code'] ?? ''));
        }
        return [
            'event' => 'messages.update',
            'instance' => $instance,
            'instance_name' => $instance,
            'external_event_id' => $id . '|' . $mapped,
            'external_message_id' => $id,
            'remote_jid' => $recipient !== '' ? $recipient . '@s.whatsapp.net' : '',
            'message_status' => $mapped,
            'status' => $mapped,
            // A missing receipt timestamp is unknown; the persistence layer
            // must not turn local processing time into a provider event time.
            'timestamp' => isset($status['timestamp']) && is_numeric($status['timestamp'])
                ? (int) $status['timestamp']
                : null,
            'provider_name' => 'meta_cloud',
            'provider_payload_id' => $id,
            'meta_phone_number_id' => $phoneNumberId,
            'delivery_error' => mb_substr($error, 0, 1000),
            'delivery_error_code' => $errorCode !== '' ? substr($errorCode, 0, 64) : null,
        ];
    }

    private function messageType(string $type): string
    {
        if ($type === 'order') return 'unsupported';
        return in_array($type, ['text','image','audio','video','document','sticker','reaction','location','contacts','interactive','button','template'], true)
            ? ($type === 'contacts' ? 'contact' : (in_array($type, ['interactive','button'], true) ? 'interactive' : $type))
            : 'unsupported';
    }

    private function qualifiesCustomerMessage(string $type): bool
    {
        return in_array($type, ['text', 'image', 'audio', 'video', 'document', 'sticker', 'location', 'contacts', 'interactive', 'button', 'order'], true);
    }

    private function messageText(array $message, string $type): string
    {
        if ($type === 'text') return trim((string) ($message['text']['body'] ?? ''));
        if (in_array($type, ['image','video','document'], true)) return trim((string) ($message[$type]['caption'] ?? ''));
        if ($type === 'button') return trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? ''));
        if ($type === 'interactive') {
            $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $reply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? [];
            return trim((string) ($reply['title'] ?? $reply['id'] ?? ''));
        }
        if ($type === 'reaction') return trim((string) ($message['reaction']['emoji'] ?? ''));
        if ($type === 'location') {
            $location = (array) ($message['location'] ?? []);
            return trim(implode(' - ', array_filter([(string) ($location['name'] ?? ''), (string) ($location['address'] ?? '')])));
        }
        if ($type === 'contacts') {
            $contact = (array) (($message['contacts'][0] ?? []));
            return trim((string) ($contact['name']['formatted_name'] ?? 'Contato compartilhado'));
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function structuredContent(array $message, string $type): array
    {
        if ($type === 'text') return ['text' => trim((string) ($message['text']['body'] ?? ''))];

        if (in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            $media = is_array($message[$type] ?? null) ? $message[$type] : [];
            return [
                'attachment' => [
                    'kind' => $type,
                    'provider_media_id' => trim((string) ($media['id'] ?? '')) ?: null,
                    'mime_type' => trim((string) ($media['mime_type'] ?? '')) ?: null,
                    'file_name' => trim((string) ($media['filename'] ?? '')) ?: null,
                    'caption' => trim((string) ($media['caption'] ?? '')),
                    'is_voice_note' => $type === 'audio' && (bool) ($media['voice'] ?? $media['ptt'] ?? false),
                    'sha256' => trim((string) ($media['sha256'] ?? '')) ?: null,
                ],
            ];
        }

        if ($type === 'location') {
            $location = is_array($message['location'] ?? null) ? $message['location'] : [];
            return ['location' => [
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'name' => trim((string) ($location['name'] ?? '')) ?: null,
                'address' => trim((string) ($location['address'] ?? '')) ?: null,
            ]];
        }

        if ($type === 'contacts') {
            $items = [];
            foreach ((array) ($message['contacts'] ?? []) as $contact) {
                if (!is_array($contact)) continue;
                $phones = [];
                foreach ((array) ($contact['phones'] ?? []) as $phone) {
                    if (is_array($phone)) $phone = $phone['phone'] ?? $phone['wa_id'] ?? '';
                    if (is_scalar($phone) && trim((string) $phone) !== '') $phones[] = trim((string) $phone);
                }
                $emails = [];
                foreach ((array) ($contact['emails'] ?? []) as $email) {
                    if (is_array($email)) $email = $email['email'] ?? '';
                    if (is_scalar($email) && trim((string) $email) !== '') $emails[] = trim((string) $email);
                }
                $items[] = [
                    'display_name' => trim((string) ($contact['name']['formatted_name'] ?? '')) ?: null,
                    'phones' => array_slice($phones, 0, 10),
                    'emails' => array_slice($emails, 0, 10),
                    'organization' => trim((string) ($contact['org']['company'] ?? '')) ?: null,
                ];
            }
            return ['contact' => ['contacts' => array_slice($items, 0, 10)]];
        }

        if ($type === 'interactive' || $type === 'button') {
            $interactive = $type === 'interactive' ? (array) ($message['interactive'] ?? []) : (array) ($message['button'] ?? []);
            $reply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? $interactive;
            return ['interactive' => [
                'kind' => $type === 'button' ? 'button' : (string) ($interactive['type'] ?? 'interactive'),
                'id' => trim((string) ($reply['id'] ?? $interactive['payload'] ?? '')) ?: null,
                'label' => trim((string) ($reply['title'] ?? $reply['text'] ?? $interactive['text'] ?? '')) ?: null,
                'description' => trim((string) ($reply['description'] ?? '')) ?: null,
                'context' => null,
            ]];
        }

        if ($type === 'reaction') {
            $reaction = (array) ($message['reaction'] ?? []);
            return ['reaction' => [
                'emoji' => trim((string) ($reaction['emoji'] ?? '')) ?: null,
                'message_id' => trim((string) ($reaction['message_id'] ?? '')) ?: null,
                'removed' => trim((string) ($reaction['emoji'] ?? '')) === '',
            ]];
        }

        if ($type === 'template') {
            $template = (array) ($message['template'] ?? []);
            return ['template' => [
                'name' => trim((string) ($template['name'] ?? '')) ?: null,
                'language' => trim((string) ($template['language']['code'] ?? $template['language'] ?? '')) ?: null,
                'category' => trim((string) ($template['category'] ?? '')) ?: null,
                'header' => null,
                'body' => null,
                'footer' => null,
                'resolved_parameters' => [],
                'buttons' => [],
                'media_reference' => null,
            ]];
        }

        return [];
    }
}
