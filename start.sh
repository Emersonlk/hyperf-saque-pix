#!/bin/bash

# Script simples para iniciar o projeto
# Apenas garante que tudo está funcionando: Docker, MySQL, Hyperf, etc.

set -e

# Obter diretório do script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "🚀 =========================================="
echo "🚀 Iniciando Projeto - Saque PIX TecnoFit"
echo "🚀 =========================================="
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Função para verificar se comando existe
check_command() {
    if ! command -v $1 &> /dev/null; then
        echo -e "${RED}❌ $1 não está instalado${NC}"
        exit 1
    fi
    echo -e "${GREEN}✅ $1 encontrado${NC}"
}

# Função para detectar docker-compose
detect_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        DOCKER_COMPOSE_CMD="docker-compose"
    elif docker compose version &> /dev/null; then
        DOCKER_COMPOSE_CMD="docker compose"
    else
        echo -e "${RED}❌ docker-compose não está instalado${NC}"
        exit 1
    fi
    echo -e "${GREEN}✅ docker-compose detectado: $DOCKER_COMPOSE_CMD${NC}"
}

# Verificar pré-requisitos
echo "📋 Verificando pré-requisitos..."
check_command docker
detect_docker_compose
echo ""

# Verificar arquivos essenciais
if [ ! -f "docker-compose.yml" ] || [ ! -f "Dockerfile" ] || [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Arquivos essenciais não encontrados${NC}"
    echo "Diretório atual: $(pwd)"
    exit 1
fi

# Verificar se Docker está acessível
if ! docker info &> /dev/null; then
    echo -e "${RED}❌ Docker não está acessível${NC}"
    echo "Certifique-se de que o Docker Desktop está rodando."
    exit 1
fi

# Limpar ambiente anterior (opcional - comentado para não limpar sempre)
# echo "🧹 Limpando ambiente anterior..."
# $DOCKER_COMPOSE_CMD down -v 2>/dev/null || true
# echo ""

# Construir imagem se necessário
echo "🔨 Verificando imagem Docker..."
if ! docker images | grep -q hyperf-skeleton; then
    echo -e "${YELLOW}⏳ Imagem não encontrada, construindo...${NC}"
    $DOCKER_COMPOSE_CMD build --no-cache
else
    echo -e "${GREEN}✅ Imagem já existe${NC}"
fi
echo ""

# Iniciar serviços
echo "🚀 Iniciando serviços..."
$DOCKER_COMPOSE_CMD up -d
echo ""

# Aguardar containers iniciarem
echo "⏳ Aguardando containers iniciarem..."
sleep 10

# Verificar se containers estão rodando
echo "🔍 Verificando containers..."
if ! docker ps | grep -q hyperf-skeleton; then
    echo -e "${RED}❌ Container hyperf-skeleton não está rodando${NC}"
    echo "📋 Logs:"
    docker logs hyperf-skeleton --tail 20 2>&1 || true
    exit 1
fi
echo -e "${GREEN}✅ Container hyperf-skeleton está rodando${NC}"

if ! docker ps | grep -q hyperf-mysql; then
    echo -e "${RED}❌ Container hyperf-mysql não está rodando${NC}"
    exit 1
fi
echo -e "${GREEN}✅ Container hyperf-mysql está rodando${NC}"

if ! docker ps | grep -q hyperf-mailhog; then
    echo -e "${RED}❌ Container hyperf-mailhog não está rodando${NC}"
    exit 1
fi
echo -e "${GREEN}✅ Container hyperf-mailhog está rodando${NC}"
echo ""

# Aguardar MySQL estar pronto
echo "⏳ Aguardando MySQL estar pronto..."
MYSQL_READY=0
for i in {1..60}; do
    # Primeiro verifica se o container está rodando
    if ! docker ps | grep -q hyperf-mysql; then
        sleep 1
        continue
    fi
    
    # Verifica se o MySQL aceita ping
    if docker exec hyperf-mysql mysqladmin ping -h localhost -uroot -proot --silent 2>/dev/null; then
        # Aguarda um pouco mais para garantir que está totalmente pronto
        sleep 2
        
        # Testa uma conexão real ao banco de dados
        if docker exec hyperf-mysql mysql -uroot -proot -e "SELECT 1;" 2>/dev/null > /dev/null; then
            echo -e "${GREEN}✅ MySQL está pronto${NC}"
            MYSQL_READY=1
            break
        fi
    fi
    
    if [ $i -eq 60 ]; then
        echo -e "${RED}❌ MySQL não ficou pronto a tempo${NC}"
        echo "📋 Últimos logs do MySQL:"
        docker logs hyperf-mysql --tail 20 2>&1 || true
        exit 1
    fi
    sleep 1
done

if [ $MYSQL_READY -eq 0 ]; then
    echo -e "${RED}❌ MySQL não está pronto${NC}"
    exit 1
fi
echo ""

# Verificar e instalar dependências se necessário
echo "📦 Verificando dependências..."
if ! docker exec hyperf-skeleton test -f /opt/www/vendor/autoload.php 2>/dev/null; then
    echo -e "${YELLOW}⚠️ Dependências não encontradas, instalando...${NC}"
    docker exec hyperf-skeleton composer install --no-dev -o 2>&1 || {
        echo -e "${RED}❌ Erro ao instalar dependências${NC}"
        exit 1
    }
    echo -e "${GREEN}✅ Dependências instaladas${NC}"
else
    echo -e "${GREEN}✅ Dependências já instaladas${NC}"
fi
echo ""

# Executar migrations
echo "🗄️ Executando migrations..."

# Verificar se tabelas já existem
TABLES_EXIST=$(docker exec hyperf-mysql mysql -uroot -proot hyperf -N -e "SHOW TABLES LIKE 'account';" 2>/dev/null || echo "")

if [ -n "$TABLES_EXIST" ]; then
    echo -e "${GREEN}✅ Tabelas já existem, pulando migrations${NC}"
else
    echo -e "${YELLOW}⏳ Criando tabelas...${NC}"
    
    # Tentar executar via comando migrate (se existir)
    MIGRATE_OUTPUT=$(docker exec hyperf-skeleton php bin/hyperf.php migrate 2>&1 || true)
    
    if echo "$MIGRATE_OUTPUT" | grep -q "not defined\|Command.*is not defined"; then
        # Se comando não existe, executar SQL diretamente
        echo -e "${YELLOW}   Comando migrate não disponível, executando SQL diretamente...${NC}"
        
        if [ -f "migrations.sql" ]; then
            # Tentar executar migrations com retry
            MIGRATION_SUCCESS=0
            for retry in {1..3}; do
                if docker exec -i hyperf-mysql mysql -uroot -proot hyperf < migrations.sql 2>&1; then
                    echo -e "${GREEN}✅ Migrations executadas via SQL${NC}"
                    MIGRATION_SUCCESS=1
                    break
                else
                    if [ $retry -lt 3 ]; then
                        echo -e "${YELLOW}   Tentativa $retry falhou, aguardando 3 segundos antes de tentar novamente...${NC}"
                        sleep 3
                    fi
                fi
            done
            
            if [ $MIGRATION_SUCCESS -eq 0 ]; then
                echo -e "${RED}❌ Erro ao executar migrations após 3 tentativas${NC}"
                echo "💡 Verifique se o MySQL está acessível:"
                echo "   docker exec hyperf-mysql mysql -uroot -proot -e 'SELECT 1;'"
                echo "💡 Tente executar manualmente:"
                echo "   docker exec -i hyperf-mysql mysql -uroot -proot hyperf < migrations.sql"
                exit 1
            fi
        else
            echo -e "${RED}❌ Arquivo migrations.sql não encontrado${NC}"
            echo "💡 Criando tabelas manualmente..."
            # Criar tabelas via SQL inline
            docker exec hyperf-mysql mysql -uroot -proot hyperf -e "
            CREATE TABLE IF NOT EXISTS \`account\` (
              \`id\` CHAR(36) NOT NULL PRIMARY KEY,
              \`name\` VARCHAR(255) NOT NULL,
              \`balance\` DECIMAL(15,2) DEFAULT 0.00,
              \`created_at\` TIMESTAMP NULL DEFAULT NULL,
              \`updated_at\` TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS \`account_withdraw\` (
              \`id\` CHAR(36) NOT NULL PRIMARY KEY,
              \`account_id\` CHAR(36) NOT NULL,
              \`method\` VARCHAR(50) NOT NULL,
              \`amount\` DECIMAL(15,2) NOT NULL,
              \`scheduled\` BOOLEAN DEFAULT FALSE,
              \`scheduled_for\` DATETIME NULL DEFAULT NULL,
              \`done\` BOOLEAN DEFAULT FALSE,
              \`error\` BOOLEAN DEFAULT FALSE,
              \`error_reason\` TEXT NULL DEFAULT NULL,
              \`created_at\` TIMESTAMP NULL DEFAULT NULL,
              \`updated_at\` TIMESTAMP NULL DEFAULT NULL,
              FOREIGN KEY (\`account_id\`) REFERENCES \`account\`(\`id\`) ON DELETE CASCADE,
              INDEX \`idx_scheduled\` (\`scheduled\`, \`scheduled_for\`, \`done\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS \`account_withdraw_pix\` (
              \`account_withdraw_id\` CHAR(36) NOT NULL PRIMARY KEY,
              \`type\` VARCHAR(50) NOT NULL,
              \`key\` VARCHAR(255) NOT NULL,
              \`created_at\` TIMESTAMP NULL DEFAULT NULL,
              \`updated_at\` TIMESTAMP NULL DEFAULT NULL,
              FOREIGN KEY (\`account_withdraw_id\`) REFERENCES \`account_withdraw\`(\`id\`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            " 2>&1 && echo -e "${GREEN}✅ Tabelas criadas${NC}" || {
                echo -e "${RED}❌ Erro ao criar tabelas${NC}"
                exit 1
            }
        fi
    else
        echo -e "${GREEN}✅ Migrations executadas${NC}"
    fi
fi
echo ""

# Criar conta de teste
echo "👤 Criando conta de teste..."
ACCOUNT_EXISTS=$(docker exec hyperf-mysql mysql -uroot -proot hyperf -N -e "SELECT COUNT(*) FROM account WHERE name = 'João Silva';" 2>/dev/null || echo "0")
ACCOUNT_ID=""

if [ "$ACCOUNT_EXISTS" = "0" ] || [ -z "$ACCOUNT_EXISTS" ]; then
    ACCOUNT_ID=$(docker exec hyperf-mysql mysql -uroot -proot hyperf -N -e "
    INSERT INTO account (id, name, balance, created_at, updated_at) 
    VALUES (UUID(), 'João Silva', 1000.00, NOW(), NOW());
    SELECT id FROM account WHERE name = 'João Silva' ORDER BY created_at DESC LIMIT 1;
    " 2>&1)
    
    # Remover quebras de linha e espaços extras
    ACCOUNT_ID=$(echo "$ACCOUNT_ID" | tr -d '\n\r ' | grep -o '[a-f0-9-]\{36\}' | head -1)
    
    if [ -n "$ACCOUNT_ID" ] && [ ${#ACCOUNT_ID} -eq 36 ]; then
        echo -e "${GREEN}✅ Conta de teste criada: $ACCOUNT_ID${NC}"
        echo -e "${YELLOW}   Nome: João Silva${NC}"
        echo -e "${YELLOW}   Saldo: R$ 1000,00${NC}"
    else
        echo -e "${YELLOW}⚠️ Não foi possível criar conta de teste automaticamente${NC}"
        echo "   Você pode criar manualmente via SQL ou API"
        ACCOUNT_ID=""
    fi
else
    ACCOUNT_ID=$(docker exec hyperf-mysql mysql -uroot -proot hyperf -N -e "SELECT id FROM account WHERE name = 'João Silva' LIMIT 1;" 2>/dev/null | tr -d '\n\r ')
    echo -e "${GREEN}✅ Conta de teste já existe: $ACCOUNT_ID${NC}"
fi
echo ""

# Aguardar Hyperf iniciar completamente
echo "⏳ Aguardando Hyperf iniciar completamente..."
for i in {1..30}; do
    if curl -s http://localhost:9501 > /dev/null 2>&1; then
        echo -e "${GREEN}✅ Hyperf está respondendo${NC}"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${YELLOW}⚠️ Hyperf ainda não está respondendo (pode levar mais tempo)${NC}"
        echo "   Verifique os logs: docker logs hyperf-skeleton"
        break
    fi
    sleep 2
done
echo ""

# Resumo final
echo "=========================================="
echo -e "${GREEN}✅ Projeto iniciado com sucesso!${NC}"
echo "=========================================="
echo ""
echo "🌐 Acessos:"
echo "  - API: http://localhost:9501"
echo "  - Mailhog UI: http://localhost:8025"
echo "  - MySQL: localhost:3306"
echo ""
echo "📝 Comandos úteis:"
echo "  - Ver logs: docker logs hyperf-skeleton -f"
echo "  - Parar: $DOCKER_COMPOSE_CMD down"
echo "  - Reiniciar: $DOCKER_COMPOSE_CMD restart"
echo ""
echo "🧪 Pronto para testar no Postman!"
echo ""
echo "💡 Exemplo de requisição:"
if [ -n "$ACCOUNT_ID" ] && [ ${#ACCOUNT_ID} -eq 36 ]; then
    echo "  POST http://localhost:9501/account/$ACCOUNT_ID/balance/withdraw"
else
    echo "  POST http://localhost:9501/account/{accountId}/balance/withdraw"
fi
echo ""
