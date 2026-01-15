## 🚀 Instalação e Execução

### Opção 1: Início Rápido (Recomendado)

Execute o script simples que apenas inicia o projeto:

```bash
git clone <url-do-repositorio>
cd hyperf-saque-pix
chmod +x start.sh
./start.sh
```

### 🪟 Windows (PowerShell)

No Windows, use o script PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\start.ps1
```

Ou simplesmente:

```powershell
.\start.ps1
```

**Nota:** O script `start.ps1` é equivalente ao `start.sh` e inclui todas as correções para compatibilidade com Windows.
```

Este script:
- ✅ Verifica pré-requisitos (Docker, Docker Compose)
- ✅ Constrói e inicia todos os serviços
- ✅ Instala dependências automaticamente
- ✅ Executa migrations (cria tabelas)
- ✅ Cria conta de teste automaticamente
- ✅ Verifica se tudo está funcionando

**Pronto para testar no Postman!**

O script cria automaticamente uma conta de teste:
- **Nome:** João Silva
- **Saldo:** R$ 1.000,00
- **ID:** Será exibido no final da execução

**📖 Guias Disponíveis:**
- `INICIO_RAPIDO_WSL.md` - **⚡ Início rápido para Windows + WSL (5 minutos)**
- `GUIA_WSL_WINDOWS.md` - **Guia completo para Windows + WSL** 🪟
- `SOLUCAO_PROBLEMAS.md` - Soluções para problemas comuns
- `PORQUE_MIGRATE_NAO_FUNCIONA.md` - Explicação sobre o comando migrate

### Opção 2: Instalação Manual

#### 1. Clonar e configurar

```bash
git clone <repository>
cd hyperf-skeleton
```

#### 2. Iniciar serviços com Docker Compose

```bash
docker-compose up -d
```

Isso iniciará:
- **Hyperf** na porta 9501
- **MySQL 8** na porta 3306
- **Mailhog** nas portas 1025 (SMTP) e 8025 (Web UI)

### 3. Executar migrations

```bash
docker exec hyperf-skeleton php bin/hyperf.php migrate
```

### 4. Conta de teste

O script `start.sh` cria automaticamente uma conta de teste. Se precisar criar manualmente:

```bash
docker exec -it hyperf-mysql mysql -uroot -proot hyperf -e "
INSERT INTO account (id, name, balance, created_at, updated_at) 
VALUES (UUID(), 'João Silva', 1000.00, NOW(), NOW());
"
```

Ou via SQL direto:

```sql
INSERT INTO account (id, name, balance, created_at, updated_at) 
VALUES (UUID(), 'João Silva', 1000.00, NOW(), NOW());
```

## 📡 API

### Realizar Saque

**Endpoint:** `POST /account/{accountId}/balance/withdraw`

**Body:**
```json
{
  "method": "PIX",
  "pix": {
    "type": "email",
    "key": "usuario@email.com"
  },
  "amount": 150.75,
  "schedule": null
}
```

**Saque Agendado:**
```json
{
  "method": "PIX",
  "pix": {
    "type": "email",
    "key": "usuario@email.com"
  },
  "amount": 200.00,
  "schedule": "2026-01-15 15:00"
}
```

**Resposta de Sucesso:**
```json
{
  "success": true,
  "data": {
    "id": "uuid-do-saque",
    "account_id": "uuid-da-conta",
    "method": "PIX",
    "amount": 150.75,
    "scheduled": false,
    "scheduled_for": null,
    "done": true,
    "error": false,
    "error_reason": null
  }
}
```

**Resposta de Erro:**
```json
{
  "error": "Saldo insuficiente para realizar o saque."
}
```

## 🔄 Processamento de Saques Agendados

O sistema possui um processo em background (`ScheduledWithdrawsProcess`) que executa a cada 1 minuto para processar saques agendados pendentes.

O processo:
1. Busca saques agendados com `scheduled_for <= agora` e `done = false`
2. Processa cada saque (deduz saldo, envia email)
3. Registra erros caso não haja saldo suficiente

## 📧 Email

Após um saque ser processado, um email é enviado automaticamente para a chave PIX informada contendo:
- Valor sacado
- Data e hora do saque
- Dados da chave PIX

**Acessar Mailhog Web UI:** http://localhost:8025
