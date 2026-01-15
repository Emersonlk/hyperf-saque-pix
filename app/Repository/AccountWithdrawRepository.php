<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AccountWithdraw;
use Carbon\Carbon;

class AccountWithdrawRepository
{
    /**
     * Cria um novo saque
     */
    public function create(array $data): AccountWithdraw
    {
        $withdraw = new AccountWithdraw();
        $withdraw->fill($data);
        $withdraw->save();
        return $withdraw;
    }

    /**
     * Busca um saque por ID
     */
    public function find(string $withdrawId): ?AccountWithdraw
    {
        return AccountWithdraw::find($withdrawId);
    }

    /**
     * Busca saques agendados pendentes (retorna Collection)
     */
    public function getPendingScheduled(\DateTime $until)
    {
        // Usa NOW() do MySQL para garantir que a comparação seja feita no timezone do banco
        return AccountWithdraw::where('scheduled', 1)
            ->where('done', 0)
            ->whereRaw('scheduled_for <= NOW()')
            ->get();
    }

    /**
     * Marca um saque como processado
     */
    public function markAsProcessed(string $withdrawId, bool $error = false, ?string $errorReason = null): bool
    {
        $data = [
            'done' => true,
            'error' => $error,
        ];

        if ($errorReason !== null) {
            $data['error_reason'] = $errorReason;
        }

        return AccountWithdraw::where('id', $withdrawId)->update($data) > 0;
    }

    /**
     * Atualiza um saque
     */
    public function update(string $withdrawId, array $data): bool
    {
        return AccountWithdraw::where('id', $withdrawId)->update($data) > 0;
    }

    /**
     * Busca saque com relacionamentos
     */
    public function findWithRelations(string $withdrawId): ?AccountWithdraw
    {
        return AccountWithdraw::with(['account', 'pix'])->find($withdrawId);
    }
}
