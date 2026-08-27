<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use InvalidArgumentException;

/** Normalizes approved Meta definitions into the provider-neutral template DTO. */
final class Template_parser_service
{
    /** @return array<string,mixed> */
    public function parse(array $row): array
    {
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];
        $fields = [];
        $unsupported = [];
        $header = null;
        $body = null;
        $footer = null;
        $buttons = [];

        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                $unsupported[] = 'malformed_component_' . $index;
                continue;
            }
            $type = strtoupper(trim((string) ($component['type'] ?? '')));
            if ($type === 'HEADER') {
                $format = strtoupper(trim((string) ($component['format'] ?? 'TEXT')));
                if ($format === 'TEXT') {
                    $text = trim((string) ($component['text'] ?? ''));
                    $header = ['type' => 'text', 'text' => $text];
                    $this->variables($text, 'header', $fields, $unsupported);
                } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $kind = strtolower($format);
                    $header = ['type' => 'media', 'kind' => $kind, 'format' => $format];
                    $fields[] = ['key' => 'header_media', 'location' => 'header', 'type' => $kind, 'required' => true];
                } else {
                    $unsupported[] = 'header_' . strtolower($format ?: 'unknown');
                }
                continue;
            }
            if ($type === 'BODY') {
                $text = trim((string) ($component['text'] ?? ''));
                $body = ['type' => 'text', 'text' => $text];
                $this->variables($text, 'body', $fields, $unsupported);
                continue;
            }
            if ($type === 'FOOTER') {
                $footer = ['type' => 'text', 'text' => trim((string) ($component['text'] ?? ''))];
                continue;
            }
            if ($type === 'BUTTONS') {
                foreach ((array) ($component['buttons'] ?? []) as $buttonIndex => $button) {
                    if (!is_array($button)) {
                        $unsupported[] = 'malformed_button_' . $buttonIndex;
                        continue;
                    }
                    $buttonType = strtoupper(trim((string) ($button['type'] ?? '')));
                    $mapped = [
                        'index' => (int) $buttonIndex,
                        'type' => strtolower($buttonType),
                        'text' => trim((string) ($button['text'] ?? '')),
                        'url' => trim((string) ($button['url'] ?? '')),
                        'phone_number' => trim((string) ($button['phone_number'] ?? '')),
                    ];
                    $buttons[] = $mapped;
                    if (!in_array($buttonType, ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'], true)) {
                        $unsupported[] = 'button_' . strtolower($buttonType ?: 'unknown');
                        continue;
                    }
                    if ($buttonType === 'URL') {
                        $this->variables($mapped['url'], 'button.' . (int) $buttonIndex, $fields, $unsupported, true);
                    }
                }
                continue;
            }
            if ($type !== '') $unsupported[] = 'component_' . strtolower($type);
        }

        $status = strtolower(trim((string) ($row['provider_status'] ?? $row['status'] ?? 'unknown')));
        $approved = $status === 'approved';
        $sendable = $approved && !empty($row['active']) && $unsupported === [];
        $reason = $unsupported !== [] ? implode(',', array_values(array_unique($unsupported))) : null;
        if (!$approved) $reason = 'TEMPLATE_NOT_APPROVED';
        if (empty($row['active'])) $reason = $reason ?: 'TEMPLATE_NOT_SENDABLE';
        $previewBody = is_array($body) ? (string) ($body['text'] ?? '') : '';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'provider_template_id' => (string) ($row['provider_template_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'language' => (string) ($row['language_code'] ?? $row['language'] ?? 'pt_BR'),
            'category' => (string) ($row['category'] ?? ''),
            'status' => $status,
            'sendable' => $sendable,
            'unsupported_reason' => $reason,
            'header' => $header,
            'body' => $body,
            'footer' => $footer,
            'buttons' => $buttons,
            'fields' => array_values($fields),
            'preview' => ['body' => $previewBody, 'header' => $header['text'] ?? null, 'footer' => $footer['text'] ?? null],
            'last_synced_at' => $row['last_synced_at'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public function resolve(array $template, array $values): array
    {
        if (empty($template['sendable'])) {
            throw new Message_send_exception('Este template nao pode ser enviado.', 'rejected', 422, null, (string) ($template['unsupported_reason'] ?: 'TEMPLATE_NOT_SENDABLE'));
        }
        $components = [];
        $resolved = ['header' => null, 'body' => null, 'footer' => $template['footer']['text'] ?? null, 'buttons' => [], 'media_reference' => null];
        $fields = is_array($template['fields'] ?? null) ? $template['fields'] : [];
        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $value = $this->valueFor($values, $key, $field);
            if (($value === null || $value === '') && !empty($field['required'])) {
                $code = $key === 'header_media' ? 'TEMPLATE_MEDIA_REQUIRED' : 'TEMPLATE_PARAMETER_MISSING';
                throw new Message_send_exception('Parametro obrigatorio ausente no template.', 'rejected', 422, null, $code);
            }
            if ($key === 'header_media') {
                $media = $this->mediaValue($value, (string) ($field['type'] ?? ''));
                $components[] = ['type' => 'header', 'parameters' => [$media['parameter']]];
                $resolved['header'] = $media['preview'];
                $resolved['media_reference'] = $media['reference'];
                continue;
            }
            $text = $this->textValue($value);
            if ($text === '') throw new Message_send_exception('Parametro de template vazio ou invalido.', 'rejected', 422, null, 'TEMPLATE_PARAMETER_INVALID');
            [$location, $position] = array_pad(explode('.', $key, 3), 2, '');
            if ($location === 'body') {
                $components = $this->appendTextParameter($components, 'body', $text);
                $resolved['body'] = $this->replace($resolved['body'] ?? ($template['body']['text'] ?? ''), (int) $position, $text);
            } elseif ($location === 'header') {
                $components = $this->appendTextParameter($components, 'header', $text);
                $resolved['header'] = $this->replace($resolved['header'] ?? ($template['header']['text'] ?? ''), (int) $position, $text);
            } elseif ($location === 'button') {
                $index = (int) $position;
                $components = $this->appendButtonParameter($components, $index, $text);
                $resolved['buttons'][$index] = ['index' => $index, 'value' => $text];
            }
        }
        if ($components === [] && !empty($template['body']['text'])) {
            $resolved['body'] = $template['body']['text'];
        }
        return [
            'components' => $this->mergeComponents($components),
            'resolved' => $resolved,
            'preview' => trim((string) ($resolved['body'] ?? $template['body']['text'] ?? '')),
        ];
    }

    private function variables(string $text, string $location, array &$fields, array &$unsupported, bool $button = false): void
    {
        if ($text === '') return;
        $open = substr_count($text, '{{');
        $close = substr_count($text, '}}');
        if ($open !== $close) {
            $unsupported[] = 'malformed_' . $location;
            return;
        }
        preg_match_all('/\{\{([^{}]*)\}\}/', $text, $matches);
        $positions = [];
        foreach ($matches[1] ?? [] as $raw) {
            $raw = trim((string) $raw);
            if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
                $unsupported[] = 'malformed_' . $location;
                continue;
            }
            $positions[] = (int) $raw;
        }
        if ($positions !== []) {
            if (count($positions) !== count(array_unique($positions))) $unsupported[] = 'duplicate_' . $location;
            $expected = range(1, max($positions));
            sort($positions);
            if ($positions !== $expected) $unsupported[] = 'gap_' . $location;
            foreach (array_values(array_unique($positions)) as $position) {
                $fields[] = [
                    'key' => $location . '.' . $position,
                    'location' => $button ? 'button' : $location,
                    'type' => 'text',
                    'position' => $position,
                    'required' => true,
                ];
            }
        }
    }

    private function valueFor(array $values, string $key, array $field)
    {
        if (array_key_exists($key, $values)) return $values[$key];
        [$location, $position] = array_pad(explode('.', $key, 3), 2, '');
        if ($location === 'header_media') return $values['header_media'] ?? null;
        if (isset($values[$location]) && is_array($values[$location])) {
            return $values[$location][max(0, (int) $position - 1)] ?? $values[$location][$position] ?? null;
        }
        return null;
    }

    private function textValue($value): string
    {
        if (!is_scalar($value)) return '';
        $value = trim((string) $value);
        return strlen($value) <= 4096 ? $value : '';
    }

    private function mediaValue($value, string $expectedKind): array
    {
        if (!is_array($value)) throw new Message_send_exception('A midia do template e obrigatoria.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        $kind = strtolower(trim((string) ($value['kind'] ?? $value['type'] ?? '')));
        if ($kind !== $expectedKind) throw new Message_send_exception('O tipo da midia nao corresponde ao header do template.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        $localId = (int) ($value['local_media_id'] ?? 0);
        if ($localId < 1) {
            throw new Message_send_exception('A midia do template precisa ser selecionada no armazenamento local.', 'rejected', 422, null, 'TEMPLATE_MEDIA_INVALID');
        }
        $node = ['local_media_id' => $localId];
        return [
            'parameter' => ['type' => $kind, $kind => $node],
            'preview' => ['kind' => $kind, 'local_media_id' => $localId],
            'reference' => ['kind' => $kind, 'local_media_id' => $localId],
        ];
    }

    private function replace(string $text, int $position, string $value): string
    {
        return str_replace('{{' . $position . '}}', $value, $text);
    }

    private function appendTextParameter(array $components, string $type, string $text): array
    {
        foreach ($components as &$component) {
            if (($component['type'] ?? '') === $type) {
                $component['parameters'][] = ['type' => 'text', 'text' => $text];
                return $components;
            }
        }
        return array_merge($components, [['type' => $type, 'parameters' => [['type' => 'text', 'text' => $text]]]]);
    }

    private function appendButtonParameter(array $components, int $index, string $text): array
    {
        return array_merge($components, [['type' => 'button', 'sub_type' => 'url', 'index' => (string) $index, 'parameters' => [['type' => 'text', 'text' => $text]]]]);
    }

    private function mergeComponents(array $components): array
    {
        $result = [];
        foreach ($components as $component) {
            if (($component['type'] ?? '') === 'button') {
                $result[] = $component;
                continue;
            }
            $type = $component['type'] ?? '';
            $found = false;
            foreach ($result as &$existing) {
                if (($existing['type'] ?? '') === $type) {
                    $existing['parameters'] = array_merge($existing['parameters'] ?? [], $component['parameters'] ?? []);
                    $found = true;
                    break;
                }
            }
            if (!$found) $result[] = $component;
        }
        return $result;
    }
}
