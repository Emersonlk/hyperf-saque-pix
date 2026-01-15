<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawService;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class ProcessScheduledWithdrawsCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
        private WithdrawService $withdrawService
    ) {
        parent::__construct('withdraw:process-scheduled');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Processa saques agendados pendentes');
    }

    public function handle(): void
    {
        $this->info('Processando saques agendados...');

        try {
            $processed = $this->withdrawService->processScheduledWithdraws();
            
            if ($processed > 0) {
                $this->info("Processados {$processed} saque(s) agendado(s).");
            } else {
                $this->info('Nenhum saque agendado pendente para processar.');
            }
        } catch (\Exception $e) {
            $this->error("Erro ao processar saques agendados: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
