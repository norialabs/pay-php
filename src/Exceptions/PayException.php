<?php

namespace NoriaLabs\Pay\Exceptions;

use RuntimeException;
use Throwable;

class PayException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?array $details = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function network(string $message, ?Throwable $previous = null): self
    {
        return new self('network_error', 0, $message, null, null, $previous);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function fromResponse(int $status, array $body): self
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return new self(
            is_string($error['code'] ?? null) ? $error['code'] : 'internal_error',
            $status,
            is_string($error['message'] ?? null) ? $error['message'] : "Request failed with status {$status}",
            is_array($error['details'] ?? null) ? $error['details'] : null,
            is_string($error['request_id'] ?? null) ? $error['request_id'] : null,
        );
    }

    /**
     * The provider never answered. The payer may already have been debited, so this is polled,
     * never re-sent.
     */
    public function isOutcomeUnknown(): bool
    {
        return $this->errorCode === 'outcome_unknown';
    }

    public function transactionId(): ?string
    {
        $id = $this->details['transaction_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * 409 is a conflict everywhere else. The one that names a transaction says the same key is
     * still in flight on another request, which resolves by waiting.
     */
    public function isInFlight(): bool
    {
        return $this->status === 409 && $this->errorCode === 'conflict' && $this->transactionId() !== null;
    }

    public function isRetryable(): bool
    {
        if (in_array($this->errorCode, [
            'outcome_unknown',
            'idempotency_mismatch',
            'provider_rejected',
            'provider_not_configured',
            'duplicate_receipt',
            'not_refundable',
            'insufficient_balance',
        ], true)) {
            return false;
        }

        if ($this->status === 409) {
            return $this->isInFlight();
        }

        return $this->status === 0 || in_array($this->status, [408, 429, 500, 502, 503, 504], true);
    }
}
