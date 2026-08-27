<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use RuntimeException;

/** Structured, sanitized errors that can be shown by the media composer. */
final class Media_engine_exception extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $httpStatus = 422,
        ?\Throwable $previous = null,
        private readonly string $idempotencyState = 'rejected',
        private readonly array $extraDetails = []
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /** @return array{code:string,retryable:bool,suggested_action:?string,idempotency_state:string} */
    public function details(): array
    {
        return array_merge([
            'code' => $this->errorCode,
            'retryable' => $this->idempotencyState === 'retryable_failure',
            'suggested_action' => $this->idempotencyState === 'ambiguous_failure'
                ? 'verify_provider_status'
                : (in_array($this->errorCode, ['MEDIA_FFMPEG_MISSING', 'MEDIA_FFPROBE_MISSING'], true)
                ? 'configure_media_binary'
                : 'review_attachment'),
            'idempotency_state' => $this->idempotencyState,
        ], $this->extraDetails);
    }
}
