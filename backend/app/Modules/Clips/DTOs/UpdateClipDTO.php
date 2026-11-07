<?php

namespace App\Modules\Clips\DTOs;

class UpdateClipDTO
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?float $startTime = null,
        public readonly ?float $endTime = null,
        public readonly ?array $metadata = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            startTime: isset($data['start_time']) ? (float) $data['start_time'] : null,
            endTime: isset($data['end_time']) ? (float) $data['end_time'] : null,
            metadata: $data['metadata'] ?? null
        );
    }
}
