<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\BusinessException;
use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawPixRepository;
use App\Repository\AccountWithdrawRepository;
use App\Service\EmailService;
use App\Service\UuidService;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class WithdrawService
{
    private LoggerInterface $logger;
    private EmailService $emailService;
    private AccountRepository $accountRepository;
    private AccountWithdrawRepository $withdrawRepository;
    private AccountWithdrawPixRepository $pixRepository;

    public function __construct(
        LoggerFactory $loggerFactory,
        EmailService $emailService,
        AccountRepository $accountRepository,
        AccountWithdrawRepository $withdrawRepository,
        AccountWithdrawPixRepository $pixRepository
    ) {
        $this->logger = $loggerFactory->get('withdraw');
        $this->emailService = $emailService;
        $this->accountRepository = $accountRepository;
        $this->withdrawRepository = $withdrawRepository;
        $this->pixRepository = $pixRepository;
    }

    /**
     * Realiza um saque da conta
     *
     * @param string $accountId
     * @param string $method
     * @param float $amount
     * @param array $pixData
     * @param string|null $schedule
     * @return AccountWithdraw
     * @throws BusinessException
     */
    public function withdraw(
        string $accountId,
        string $method,
        float $amount,
        array $pixData,
        ?string $schedule = null
    ): AccountWithdraw {
        $isScheduled = $schedule !== null;
        
        $withdraw = Db::transaction(function () use ($accountId, $method, $amount, $pixData, $schedule, $isScheduled) {
            // Valida e busca a conta
            $account = $this->validateAndGetAccount($accountId, $amount);
            
            // Cria o registro de saque
            $withdrawId = UuidService::generate();
            $withdraw = $this->createWithdraw($accountId, $method, $amount, $schedule, $isScheduled, $withdrawId);
            
            // Garante que o ID está preservado no objeto
            $withdraw->id = $withdrawId;
            
            // Cria dados PIX se necessário
            if ($method === 'PIX') {
                $this->createPixData($withdrawId, $pixData);
            }

            // Se não for agendado, processa imediatamente
            if (!$isScheduled) {
                $this->processImmediateWithdraw($withdraw, $account, $amount, $withdrawId);
            }

            return $withdraw;
        });

        // Valida o resultado
        if (!$withdraw || !$withdraw->id) {
            throw new BusinessException('Erro ao processar o saque. Tente novamente.');
        }

        // Envia email de notificação FORA da transação para não causar rollback
        // Executa em corrotina para não bloquear a resposta
        if (!$isScheduled) {
            $withdrawId = $withdraw->id;
            \Hyperf\Coroutine\go(function () use ($withdrawId) {
                try {
                    // Busca o withdraw novamente para garantir que tem todos os dados
                    $withdrawForEmail = $this->withdrawRepository->find($withdrawId);
                    
                    if ($withdrawForEmail) {
                        $this->sendWithdrawNotification($withdrawForEmail);
                    }
                } catch (\Exception $e) {
                    // Loga o erro mas não afeta o saque
                    $this->logger->warning('Aviso: Erro ao enviar email (não crítico)', [
                        'withdraw_id' => $withdrawId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
        
        return $withdraw;
    }

    /**
     * Processa um saque (deduz saldo e envia email)
     *
     * @param AccountWithdraw $withdraw
     * @param Account $account
     * @return void
     */
    public function processWithdraw(AccountWithdraw $withdraw, ?Account $account = null): void
    {
        if ($withdraw->done) {
            return;
        }

        try {
            $transactionResult = Db::transaction(function () use ($withdraw, $account) {
                // Verifica se o saque já foi processado (dentro da transação para evitar race condition)
                $withdrawCheck = \App\Model\AccountWithdraw::lockForUpdate()->find($withdraw->id);
                if (!$withdrawCheck || $withdrawCheck->done) {
                    return false;
                }
                
                $account = $account ?? $this->accountRepository->findWithLock($withdraw->account_id);
                
                if (!$account) {
                    $this->logger->warning('Conta não encontrada ao processar saque agendado', [
                        'withdraw_id' => $withdraw->id,
                        'account_id' => $withdraw->account_id,
                    ]);
                    $this->withdrawRepository->markAsProcessed(
                        $withdraw->id,
                        true,
                        'Conta não encontrada.'
                    );
                    return false;
                }

                // Verifica saldo novamente (pode ter mudado desde o agendamento)
                if ($account->balance < $withdraw->amount) {
                    $this->logger->warning('Saque falhou por saldo insuficiente', [
                        'withdraw_id' => $withdraw->id,
                        'account_id' => $withdraw->account_id,
                        'amount' => $withdraw->amount,
                        'balance' => $account->balance,
                    ]);
                    $this->withdrawRepository->markAsProcessed(
                        $withdraw->id,
                        true,
                        'Saldo insuficiente no momento do processamento.'
                    );
                    return false;
                }

                // Deduz o saldo (converte amount para float)
                $this->accountRepository->decrementBalance($withdraw->account_id, (float) $withdraw->amount);
                $account->refresh();

                // Marca como processado usando o Repository
                $this->withdrawRepository->markAsProcessed($withdraw->id, false);
                
                // Atualiza o objeto para refletir as mudanças
                $withdraw->done = true;
                $withdraw->error = false;

                $this->logger->info('Saque processado com sucesso', [
                    'withdraw_id' => $withdraw->id,
                    'account_id' => $withdraw->account_id,
                    'amount' => $withdraw->amount,
                    'novo_saldo' => $account->balance,
                ]);
                
                return true;
            });
            
            // Envia email de notificação apenas se a transação foi bem-sucedida
            if ($transactionResult === true) {
                try {
                    $this->sendWithdrawNotification($withdraw);
                } catch (\Exception $e) {
                    // Loga o erro mas não afeta o saque
                    $this->logger->warning('Aviso: Erro ao enviar email (não crítico)', [
                        'withdraw_id' => $withdraw->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao processar saque agendado', [
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Envia email de notificação do saque
     *
     * @param AccountWithdraw $withdraw
     * @return void
     */
    private function sendWithdrawNotification(AccountWithdraw $withdraw): void
    {
        try {
            // Recarrega o withdraw para garantir que tem o ID correto
            $withdraw->refresh();
            
            // Carrega o relacionamento PIX explicitamente
            $pix = $this->pixRepository->findByWithdrawId($withdraw->id);
            
            if (!$pix) {
                $this->logger->warning('Dados PIX não encontrados para envio de email', [
                    'withdraw_id' => $withdraw->id,
                ]);
                return;
            }

            // Usa o horário atual do processamento (updated_at) ao invés de created_at
            $processDate = $withdraw->updated_at ?: new \DateTime();
            
            $this->emailService->sendWithdrawNotification(
                $pix->key,
                (float) $withdraw->amount,
                $processDate,
                $pix->type
            );
        } catch (\Exception $e) {
            $this->logger->error('Erro ao enviar email de notificação', [
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Processa saques agendados pendentes
     *
     * @return int Número de saques processados
     */
    public function processScheduledWithdraws(): int
    {
        $now = new \DateTime();
        
        $processed = 0;
        
        // Processa saques um por um dentro de uma transação com lock para evitar processamento duplicado
        while (true) {
            $withdraw = Db::transaction(function () {
                // Busca um saque agendado pendente com lock (FOR UPDATE)
                // ORDER BY id garante que processos sempre tentem bloquear na mesma ordem, evitando deadlocks
                // CONVERT_TZ converte scheduled_for (timezone local) para UTC antes de comparar com NOW() (UTC)
                return \App\Model\AccountWithdraw::where('scheduled', 1)
                    ->where('done', 0)
                    ->whereRaw("CONVERT_TZ(scheduled_for, '-03:00', '+00:00') <= NOW()")
                    ->orderBy('scheduled_for', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();
            });
            
            if (!$withdraw) {
                // Não há mais saques para processar
                break;
            }
            
            try {
                $this->processWithdraw($withdraw);
                $processed++;
            } catch (\Exception $e) {
                $this->logger->error('Erro ao processar saque agendado', [
                    'withdraw_id' => $withdraw->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if ($processed > 0) {
            $this->logger->info("Processados {$processed} saque(s) agendado(s)");
        }

        return $processed;
    }

    /**
     * Valida e busca a conta com lock
     */
    private function validateAndGetAccount(string $accountId, float $amount): Account
    {
        $account = $this->accountRepository->findWithLock($accountId);
        
        if (!$account) {
            throw new BusinessException('Conta não encontrada.');
        }

        if ($account->balance < $amount) {
            throw new BusinessException('Saldo insuficiente para realizar o saque.');
        }

        return $account;
    }

    /**
     * Cria o registro de saque
     */
    private function createWithdraw(
        string $accountId,
        string $method,
        float $amount,
        ?string $schedule,
        bool $isScheduled,
        string $withdrawId
    ): AccountWithdraw {
        $scheduledFor = $isScheduled ? new \DateTime($schedule) : null;

        $withdraw = $this->withdrawRepository->create([
            'id' => $withdrawId,
            'account_id' => $accountId,
            'method' => $method,
            'amount' => $amount,
            'scheduled' => $isScheduled,
            'scheduled_for' => $scheduledFor,
            'done' => false,
            'error' => false,
        ]);
        
        // Garante que o ID está preservado
        $withdraw->id = $withdrawId;
        
        return $withdraw;
    }

    /**
     * Cria dados PIX para o saque
     */
    private function createPixData(string $withdrawId, array $pixData): void
    {
        $this->pixRepository->create([
            'account_withdraw_id' => $withdrawId,
            'type' => $pixData['type'],
            'key' => $pixData['key'],
        ]);
    }

    /**
     * Processa saque imediato (deduz saldo e marca como processado)
     */
    private function processImmediateWithdraw(AccountWithdraw $withdraw, Account $account, float $amount, string $withdrawId): void
    {
        // Deduz o saldo
        $this->accountRepository->decrementBalance($account->id, $amount);
        $account->refresh();
        
        // Marca como processado usando o Repository (UPDATE direto por ID para garantir)
        $this->withdrawRepository->markAsProcessed($withdrawId, false);
        
        // Atualiza o objeto para refletir as mudanças
        $withdraw->done = true;
        $withdraw->error = false;
        $withdraw->id = $withdrawId;
        
        $this->logger->info('Saque processado com sucesso', [
            'withdraw_id' => $withdrawId,
            'account_id' => $account->id,
            'amount' => $amount,
            'novo_saldo' => $account->balance,
        ]);
    }
}
