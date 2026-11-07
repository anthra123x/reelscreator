<?php

namespace App\Modules\Videos\DTOs;

use InvalidArgumentException;

class VideoIngestDTO
{
    public function __construct(
        public readonly string $sourceUrl,
        public readonly string $strategy = 'fixed_interval',
        public readonly int $intervalSeconds = 30,
        public readonly int $maxDurationSeconds = 60,
        public readonly string $verticalMode = 'blur_padding',
        public readonly ?string $title = null,
        public readonly array $extraParams = []
    ) {
        $allowedStrategies = ['fixed_interval', 'scene_detection', 'silence_detection', 'manual_timestamps'];
        if (! in_array($this->strategy, $allowedStrategies, true)) {
            throw new InvalidArgumentException("Unsupported strategy: {$this->strategy}");
        }

        $allowedModes = ['blur_padding', 'center_crop', 'letterbox'];
        if (! in_array($this->verticalMode, $allowedModes, true)) {
            throw new InvalidArgumentException("Unsupported vertical mode: {$this->verticalMode}");
        }

        if ($this->intervalSeconds <= 0) {
            throw new InvalidArgumentException('interval_seconds must be positive');
        }

        if ($this->maxDurationSeconds <= 0) {
            throw new InvalidArgumentException('max_duration_seconds must be positive');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sourceUrl: $data['source_url'],
            strategy: $data['strategy'] ?? 'fixed_interval',
            intervalSeconds: (int) ($data['interval_seconds'] ?? 30),
            maxDurationSeconds: (int) ($data['max_duration'] ?? $data['max_duration_seconds'] ?? 60),
            verticalMode: $data['vertical_mode'] ?? 'blur_padding',
            title: $data['title'] ?? null,
            extraParams: array_diff_key($data, array_flip([
                'source_url', 'strategy', 'interval_seconds', 'max_duration',
                'max_duration_seconds', 'vertical_mode', 'title',
            ]))
        );
    }

    public function toArray(): array
    {
        return [
            'source_url' => $this->sourceUrl,
            'strategy' => $this->strategy,
            'interval_seconds' => $this->intervalSeconds,
            'max_duration_seconds' => $this->maxDurationSeconds,
            'vertical_mode' => $this->verticalMode,
            'title' => $this->title,
            'extra_params' => $this->extraParams,
        ];
    }
}
