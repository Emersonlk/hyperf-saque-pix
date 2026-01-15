<?php

declare(strict_types=1);

namespace App\Process;

use App\Service\WithdrawService;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Process(name: 'scheduled-withdraws-processor')]
class ScheduledWithdrawsProcess extends AbstractProcess
{
    private LoggerInterface $logger;
    private WithdrawService $withdrawService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
    }

    public function handle(): void
    {
        $this->logger = $this->container->get(LoggerFactory::class)->get('scheduled-withdraws');
        $this->withdrawService = $this->container->get(WithdrawService::class);

        $this->logger->info('Processo de saques agendados iniciado');

        // Executa a cada 1 minuto
        while (true) {
            try {
                $processed = $this->withdrawService->processScheduledWithdraws();
                
                if ($processed > 0) {
                    $this->logger->info("Processados {$processed} saque(s) agendado(s)");
                }
            } catch (\Exception $e) {
                $this->logger->error('Erro ao processar saques agendados', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Aguarda 60 segundos antes da próxima execução
            sleep(60);
        }
    }
}
