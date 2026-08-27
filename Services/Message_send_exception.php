<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use RuntimeException;
use Throwable;

final class Message_send_exception extends RuntimeException
{
    public function __construct(
        string $message,
        private string $sendState,
        int $code = 422,
        ?Throwable $previous = null,
        private ?string $errorCode = null,
        private array $extraDetails = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function sendState(): string
    {
        return $this->sendState;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return array_merge([
            'code' => $this->errorCode ?: 'MESSAGE_SEND_FAILED',
            'send_state' => $this->sendState,
            'retryable' => $this->sendState === 'retryable_failure',
            'suggested_action' => $this->sendState === 'ambiguous_failure'
                ? 'verify_provider_status'
                : ($this->sendState === 'rejected' ? 'use_new_client_message_id' : null),
        ], $this->extraDetails);
    }
}
