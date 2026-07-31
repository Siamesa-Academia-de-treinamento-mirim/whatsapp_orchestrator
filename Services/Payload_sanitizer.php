<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

/**
 * Removes credentials from arbitrary payloads before they are persisted or logged.
 *
 * sanitize() is intentionally lossy and size-bounded, making it suitable for logs.
 * redact() preserves the payload shape and is used when an API response still needs
 * to be consumed by application code.
 */
class Payload_sanitizer
{
    private const REDACTED = '[REDACTED]';
    private const TRUNCATED = '[TRUNCATED]';

    private int $maxPayloadBytes;
    private int $maxStringBytes;
    private int $maxItems;
    private int $maxDepth;

    public function __construct(
        int $maxPayloadBytes = 16384,
        int $maxStringBytes = 2048,
        int $maxItems = 200,
        int $maxDepth = 12
    ) {
        $this->maxPayloadBytes = max(256, $maxPayloadBytes);
        $this->maxStringBytes = max(64, $maxStringBytes);
        $this->maxItems = max(10, $maxItems);
        $this->maxDepth = max(2, $maxDepth);
    }

    /**
     * Recursively redacts secrets and bounds the result for safe technical logs.
     *
     * @param mixed $payload
     * @param array<int, string> $sensitiveValues
     * @return mixed
     */
    public function sanitize($payload, array $sensitiveValues = [])
    {
        $sanitized = $this->walk($payload, $this->normalizeSensitiveValues($sensitiveValues), 0, true);
        $encoded = $this->encode($sanitized);

        if ($encoded === null || strlen($encoded) <= $this->maxPayloadBytes) {
            return $sanitized;
        }

        return [
            '_truncated' => true,
            'original_bytes' => strlen($encoded),
            'preview' => $this->truncate($encoded, $this->maxPayloadBytes),
        ];
    }

    /**
     * Redacts recursively without truncating a response that the caller must use.
     *
     * @param mixed $payload
     * @param array<int, string> $sensitiveValues
     * @return mixed
     */
    public function redact($payload, array $sensitiveValues = [])
    {
        return $this->walk($payload, $this->normalizeSensitiveValues($sensitiveValues), 0, false);
    }

    /**
     * Returns a bounded JSON representation suitable for a text log column.
     *
     * @param mixed $payload
     * @param array<int, string> $sensitiveValues
     */
    public function sanitize_to_json($payload, array $sensitiveValues = []): string
    {
        $encoded = $this->encode($this->sanitize($payload, $sensitiveValues));

        return $encoded ?? '{}';
    }

    /** @see sanitize_to_json() */
    public function sanitizeToJson($payload, array $sensitiveValues = []): string
    {
        return $this->sanitize_to_json($payload, $sensitiveValues);
    }

    /**
     * @param mixed $value
     * @param array<int, string> $sensitiveValues
     * @return mixed
     */
    private function walk($value, array $sensitiveValues, int $depth, bool $bounded)
    {
        if ($depth > $this->maxDepth) {
            return self::TRUNCATED;
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            $position = 0;

            foreach ($value as $key => $item) {
                if ($bounded && $position >= $this->maxItems) {
                    $result['_truncated_items'] = max(1, count($value) - $position);
                    break;
                }

                if ($this->isSensitiveKey((string) $key)) {
                    $result[$key] = self::REDACTED;
                } else {
                    $result[$key] = $this->walk($item, $sensitiveValues, $depth + 1, $bounded);
                }

                ++$position;
            }

            return $result;
        }

        if (is_string($value)) {
            $value = $this->redactString($value, $sensitiveValues);

            return $bounded ? $this->truncate($value, $this->maxStringBytes) : $value;
        }

        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));

        if ($normalized === '') {
            return false;
        }

        $exact = [
            'apikey',
            'xapikey',
            'authorization',
            'auth',
            'authentication',
            'proxyauthorization',
            'token',
            'accesstoken',
            'refreshtoken',
            'idtoken',
            'secret',
            'clientsecret',
            'webhooksecret',
            'password',
            'passwd',
            'credential',
            'credentials',
        ];

        if (in_array($normalized, $exact, true)) {
            return true;
        }

        return str_contains($normalized, 'apikey')
            || str_ends_with($normalized, 'token')
            || str_ends_with($normalized, 'secret')
            || str_ends_with($normalized, 'password');
    }

    /** @param array<int, string> $sensitiveValues */
    private function redactString(string $value, array $sensitiveValues): string
    {
        foreach ($sensitiveValues as $sensitiveValue) {
            $value = str_replace($sensitiveValue, self::REDACTED, $value);
        }

        $value = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i', '$1' . self::REDACTED, $value);
        $value = (string) preg_replace('/([?&](?:api[_-]?key|token|secret|password)=)[^&\s]+/i', '$1' . self::REDACTED, $value);

        return $value;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeSensitiveValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $suffix = '...' . self::TRUNCATED;
        $limit = max(0, $maxBytes - strlen($suffix));

        return substr($value, 0, $limit) . $suffix;
    }

    /** @param mixed $value */
    private function encode($value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? $encoded : null;
    }
}
