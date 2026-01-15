<?php

declare(strict_types=1);

namespace App\Dto;

class WithdrawRequestDto
{
    public function __construct(
        public readonly string $method,
        public readonly float $amount,
        public readonly ?PixDto $pix = null,
        public readonly ?string $schedule = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $pix = null;
        if (isset($data['pix']) && is_array($data['pix'])) {
            $pix = PixDto::fromArray($data['pix']);
        }

        return new self(
            method: $data['method'],
            amount: (float) $data['amount'],
            pix: $pix,
            schedule: $data['schedule'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'amount' => $this->amount,
            'pix' => $this->pix?->toArray(),
            'schedule' => $this->schedule,
        ];
    }
}
