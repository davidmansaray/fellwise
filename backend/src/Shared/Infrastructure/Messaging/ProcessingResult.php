<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RetryDecision;

final readonly class ProcessingResult
{
    private function __construct(
        public ProcessingStatus $status,
        public ?Envelope $envelope = null,
        public ?RetryDecision $retry = null,
        public ?string $error = null,
    ) {}

    public static function processed(Envelope $envelope): self
    {
        return new self(ProcessingStatus::Processed, $envelope);
    }

    public static function duplicate(Envelope $envelope): self
    {
        return new self(ProcessingStatus::Duplicate, $envelope);
    }

    public static function ignored(Envelope $envelope): self
    {
        return new self(ProcessingStatus::Ignored, $envelope);
    }

    public static function failed(Envelope $envelope, RetryDecision $retry, string $error): self
    {
        return new self(ProcessingStatus::Failed, $envelope, $retry, $error);
    }

    public static function rejected(string $error): self
    {
        return new self(ProcessingStatus::Rejected, error: $error);
    }
}
