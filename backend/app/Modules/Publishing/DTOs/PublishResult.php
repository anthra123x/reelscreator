<?php

namespace App\Modules\Publishing\DTOs;

class PublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $platformPostId = null,
        public readonly ?string $errorMessage = null,
        public readonly array $metadata = []
    ) {}

    public static function success(string $platformPostId, array $metadata = []): self
    {
        return new self(
            success: true,
            platformPostId: $platformPostId,
            errorMessage: null,
            metadata: $metadata
        );
    }

    public static function failure(string $errorMessage, array $metadata = []): self
    {
        return new self(
            success: false,
            platformPostId: null,
            errorMessage: $errorMessage,
            metadata: $metadata
        );
    }
}
