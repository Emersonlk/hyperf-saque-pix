<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property string $account_withdraw_id
 * @property string $type
 * @property string $key
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property AccountWithdraw $withdraw
 */
class AccountWithdrawPix extends Model
{
    protected ?string $table = 'account_withdraw_pix';

    protected array $fillable = [
        'account_withdraw_id',
        'type',
        'key',
    ];

    protected array $casts = [
        'account_withdraw_id' => 'string',
        'type' => 'string',
        'key' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function withdraw()
    {
        return $this->belongsTo(AccountWithdraw::class, 'account_withdraw_id', 'id');
    }
}
