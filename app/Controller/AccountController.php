<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\WithdrawRequestDto;
use App\Exception\BusinessException;
use App\Request\WithdrawRequest;
use App\Service\WithdrawService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/account')]
class AccountController extends AbstractController
{
    public function __construct(
        private WithdrawService $withdrawService,
        private WithdrawRequest $withdrawRequest
    ) {
    }

    #[PostMapping(path: '/{accountId}/balance/withdraw')]
    public function withdraw(
        string $accountId
    ): PsrResponseInterface {
        try {
            // Valida e retorna DTO ou resposta de erro
            $validationResult = $this->withdrawRequest->validate();
            
            if ($validationResult instanceof PsrResponseInterface) {
                return $validationResult;
            }

            /** @var WithdrawRequestDto $dto */
            $dto = $validationResult;

            // Converte DTO para formato esperado pelo service
            $pixData = $dto->pix?->toArray() ?? [];

            // Processa o saque
            $withdraw = $this->withdrawService->withdraw(
                $accountId,
                $dto->method,
                $dto->amount,
                $pixData,
                $dto->schedule
            );

            // Verifica se o withdraw foi retornado
            if (!$withdraw) {
                return $this->response->json([
                    'error' => 'Erro ao processar o saque. Tente novamente.',
                ])->withStatus(500);
            }

            $scheduledFor = null;
            if ($withdraw->scheduled_for && $withdraw->scheduled_for instanceof \DateTime) {
                $scheduledFor = $withdraw->scheduled_for->format('Y-m-d H:i:s');
            }

            return $this->response->json([
                'success' => true,
                'data' => [
                    'id' => (string) ($withdraw->id ?? ''),
                    'account_id' => (string) ($withdraw->account_id ?? ''),
                    'method' => (string) ($withdraw->method ?? ''),
                    'amount' => (float) ($withdraw->amount ?? 0),
                    'scheduled' => (bool) ($withdraw->scheduled ?? false),
                    'scheduled_for' => $scheduledFor,
                    'done' => (bool) ($withdraw->done ?? false),
                    'error' => (bool) ($withdraw->error ?? false),
                    'error_reason' => $withdraw->error_reason ? (string) $withdraw->error_reason : null,
                ],
            ]);
        } catch (BusinessException $e) {
            return $this->response->json([
                'error' => $e->getMessage(),
            ])->withStatus(400);
        } catch (\Throwable $e) {
            return $this->response->json([
                'error' => 'Erro interno do servidor.',
                'message' => $e->getMessage(),
            ])->withStatus(500);
        }
    }
}
