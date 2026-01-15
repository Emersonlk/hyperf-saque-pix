<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use function Hyperf\Support\env;

class EmailService
{
    private LoggerInterface $logger;
    private string $mailHost;
    private int $mailPort;

    public function __construct(
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get('email');
        $this->mailHost = env('MAIL_HOST', 'mailhog');
        $this->mailPort = (int) env('MAIL_PORT', 1025);
    }

    /**
     * Envia email de notificação de saque
     *
     * @param string $toEmail
     * @param float $amount
     * @param \DateTime $withdrawDate
     * @param string $pixType
     * @return void
     */
    public function sendWithdrawNotification(
        string $toEmail,
        float $amount,
        \DateTime $withdrawDate,
        string $pixType
    ): void {
        $subject = 'Saque PIX Realizado';
        $body = $this->buildEmailBody($amount, $withdrawDate, $pixType, $toEmail);

        $this->sendViaSmtp($toEmail, $subject, $body);
    }

    /**
     * Envia email via SMTP
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return void
     */
    private function sendViaSmtp(string $to, string $subject, string $body): void
    {
        $socket = null;
        try {
            $socket = @stream_socket_client(
                "tcp://{$this->mailHost}:{$this->mailPort}",
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT
            );
            
            if (!$socket) {
                $this->logger->warning('Não foi possível conectar ao SMTP (não é crítico)', [
                    'host' => $this->mailHost,
                    'port' => $this->mailPort,
                    'errno' => $errno,
                    'errstr' => $errstr,
                ]);
                return;
            }

            // Configura timeout
            stream_set_timeout($socket, 5);
            
            // Lê banner inicial
            $this->smtpCommand($socket, "");
            $this->smtpCommand($socket, "EHLO localhost");
            $this->smtpCommand($socket, "MAIL FROM:<noreply@tecnofit.com.br>");
            $this->smtpCommand($socket, "RCPT TO:<{$to}>");
            $this->smtpCommand($socket, "DATA");
            
            $message = "From: noreply@tecnofit.com.br\r\n";
            $message .= "To: {$to}\r\n";
            $message .= "Subject: {$subject}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $body;
            $message .= "\r\n.\r\n";
            
            @fwrite($socket, $message);
            $this->smtpCommand($socket, "QUIT");
            
            @fclose($socket);

            $this->logger->info('Email enviado via SMTP com sucesso', ['to' => $to]);
        } catch (\Exception $e) {
            if ($socket && is_resource($socket)) {
                @fclose($socket);
            }
            // Não loga como erro crítico, apenas como warning
            $this->logger->warning('Aviso ao enviar email via SMTP (não crítico)', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Executa comando SMTP
     *
     * @param resource $socket
     * @param string $command
     * @return string
     */
    private function smtpCommand($socket, string $command): ?string
    {
        if (!is_resource($socket)) {
            return null;
        }
        
        if (!empty($command)) {
            @fwrite($socket, $command . "\r\n");
        }
        
        $response = @fgets($socket, 515);
        return $response !== false ? trim($response) : null;
    }

    /**
     * Constrói o corpo do email
     *
     * @param float $amount
     * @param \DateTime $withdrawDate
     * @param string $pixType
     * @param string $pixKey
     * @return string
     */
    private function buildEmailBody(
        float $amount,
        \DateTime $withdrawDate,
        string $pixType,
        string $pixKey
    ): string {
        $formattedAmount = number_format($amount, 2, ',', '.');
        $formattedDate = $withdrawDate->format('d/m/Y H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .info { background-color: white; padding: 15px; margin: 10px 0; border-left: 4px solid #4CAF50; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Saque PIX Realizado</h1>
        </div>
        <div class="content">
            <p>Olá,</p>
            <p>Informamos que seu saque PIX foi realizado com sucesso.</p>
            
            <div class="info">
                <strong>Valor sacado:</strong> R$ {$formattedAmount}<br>
                <strong>Data e hora:</strong> {$formattedDate}<br>
                <strong>Tipo de chave PIX:</strong> {$pixType}<br>
                <strong>Chave PIX:</strong> {$pixKey}
            </div>
            
            <p>O valor foi debitado da sua conta digital e deve estar disponível em breve.</p>
        </div>
        <div class="footer">
            <p>TecnoFit - Conta Digital</p>
            <p>Este é um email automático, por favor não responda.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
