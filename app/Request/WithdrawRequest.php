<?php

declare(strict_types=1);

namespace App\Request;

use App\Dto\PixDto;
use App\Dto\WithdrawRequestDto;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class WithdrawRequest
{
    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response
    ) {
    }

    /**
     * Valida e retorna o DTO ou retorna resposta de erro
     */
    public function validate(): WithdrawRequestDto|PsrResponseInterface
    {
        $data = $this->request->all();

        // Validação do campo method
        if (empty($data['method'])) {
            return $this->response->json([
                'error' => 'O campo "method" é obrigatório.',
            ])->withStatus(400);
        }

        // Validação do campo amount
        if (empty($data['amount']) || !is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
            return $this->response->json([
                'error' => 'O campo "amount" deve ser um valor numérico maior que zero.',
            ])->withStatus(400);
        }

        // Validação específica para PIX
        if ($data['method'] === 'PIX') {
            if (empty($data['pix']) || !is_array($data['pix'])) {
                return $this->response->json([
                    'error' => 'Os campos "pix.type" e "pix.key" são obrigatórios para saque PIX.',
                ])->withStatus(400);
            }

            if (empty($data['pix']['type']) || empty($data['pix']['key'])) {
                return $this->response->json([
                    'error' => 'Os campos "pix.type" e "pix.key" são obrigatórios para saque PIX.',
                ])->withStatus(400);
            }

            // Valida tipo de chave PIX
            if ($data['pix']['type'] !== 'email') {
                return $this->response->json([
                    'error' => 'Apenas chaves PIX do tipo "email" são suportadas no momento.',
                ])->withStatus(400);
            }

            // Valida formato de email
            if (!filter_var($data['pix']['key'], FILTER_VALIDATE_EMAIL)) {
                return $this->response->json([
                    'error' => 'A chave PIX deve ser um email válido.',
                ])->withStatus(400);
            }
        }

        // Validação do schedule (se fornecido)
        if (!empty($data['schedule'])) {
            $scheduleTimestamp = strtotime($data['schedule']);
            if ($scheduleTimestamp === false) {
                return $this->response->json([
                    'error' => 'O campo "schedule" deve ser uma data/hora válida no formato "Y-m-d H:i".',
                ])->withStatus(400);
            }

            // Verifica se a data não está no passado
            if ($scheduleTimestamp < time()) {
                return $this->response->json([
                    'error' => 'O agendamento não pode ser no passado.',
                ])->withStatus(400);
            }
        }

        // Retorna o DTO se todas as validações passaram
        return WithdrawRequestDto::fromArray($data);
    }
}
