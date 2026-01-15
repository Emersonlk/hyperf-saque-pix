<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property string $id
 * @property string $account_id
 * @property string $method
 * @property float $amount
 * @property bool $scheduled
 * @property ?\Carbon\Carbon $scheduled_for
 * @property bool $done
 * @property bool $error
 * @property ?string $error_reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property Account $account
 * @property AccountWithdrawPix $pix
 */
class AccountWithdraw extends Model
{
    protected ?string $table = 'account_withdraw';

    protected array $fillable = [
        'id',
        'account_id',
        'method',
        'amount',
        'scheduled',
        'scheduled_for',
        'done',
        'error',
        'error_reason',
    ];

    protected array $casts = [
        'id' => 'string',
        'account_id' => 'string',
        'method' => 'string',
        'amount' => 'decimal:2',
        'scheduled' => 'boolean',
        'scheduled_for' => 'datetime',
        'done' => 'boolean',
        'error' => 'boolean',
        'error_reason' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function pix()
    {
        return $this->hasOne(AccountWithdrawPix::class, 'account_withdraw_id', 'id');
    }
}
