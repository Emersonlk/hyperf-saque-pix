<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property string $id
 * @property string $name
 * @property float $balance
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Account extends Model
{
    protected ?string $table = 'account';

    protected array $fillable = [
        'id',
        'name',
        'balance',
    ];

    protected array $casts = [
        'id' => 'string',
        'name' => 'string',
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
