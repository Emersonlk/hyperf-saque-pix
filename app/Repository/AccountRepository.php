<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Account;
use Hyperf\DbConnection\Db;

class AccountRepository
{
    /**
     * Busca uma conta com lock para evitar race condition
     */
    public function findWithLock(string $accountId): ?Account
    {
        return Account::lockForUpdate()->find($accountId);
    }

    /**
     * Busca uma conta por ID
     */
    public function find(string $accountId): ?Account
    {
        return Account::find($accountId);
    }

    /**
     * Decrementa o saldo da conta
     */
    public function decrementBalance(string $accountId, float $amount): bool
    {
        return Account::where('id', $accountId)->decrement('balance', $amount) > 0;
    }

    /**
     * Verifica se a conta tem saldo suficiente
     */
    public function hasSufficientBalance(string $accountId, float $amount): bool
    {
        $account = $this->findWithLock($accountId);
        return $account !== null && $account->balance >= $amount;
    }

    /**
     * Atualiza o saldo da conta
     */
    public function updateBalance(string $accountId, float $newBalance): bool
    {
        return Account::where('id', $accountId)->update(['balance' => $newBalance]) > 0;
    }
}
