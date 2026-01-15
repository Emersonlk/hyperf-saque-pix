<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AccountWithdrawPix;

class AccountWithdrawPixRepository
{
    /**
     * Cria dados PIX para um saque
     */
    public function create(array $data): AccountWithdrawPix
    {
        $pix = new AccountWithdrawPix();
        $pix->fill($data);
        $pix->save();
        return $pix;
    }

    /**
     * Busca dados PIX por ID do saque
     */
    public function findByWithdrawId(string $withdrawId): ?AccountWithdrawPix
    {
        return AccountWithdrawPix::where('account_withdraw_id', $withdrawId)->first();
    }

    /**
     * Busca dados PIX por ID
     */
    public function find(string $pixId): ?AccountWithdrawPix
    {
        return AccountWithdrawPix::find($pixId);
    }

    /**
     * Atualiza dados PIX
     */
    public function update(string $pixId, array $data): bool
    {
        return AccountWithdrawPix::where('id', $pixId)->update($data) > 0;
    }
}
