<?php

declare(strict_types=1);

namespace App\Dto;

class PixDto
{
    public function __construct(
        public readonly string $type,
        public readonly string $key
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            key: $data['key']
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
        ];
    }
}
