$ErrorActionPreference = "Stop"

# NOTE: keep output ASCII-only to avoid Windows PowerShell encoding/parsing issues.
function Write-Ok($msg) { Write-Host "[OK]  $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "[WARN] $msg" -ForegroundColor Yellow }
function Write-Err($msg) { Write-Host "[ERR] $msg" -ForegroundColor Red }
function Write-Info($msg) { Write-Host "[INFO] $msg" -ForegroundColor Cyan }

function Get-DockerComposeCommand {
    if (Get-Command docker-compose -ErrorAction SilentlyContinue) {
        return "docker-compose"
    }
    # docker compose (plugin)
    try {
        docker compose version | Out-Null
        return "docker compose"
    } catch {
        throw "docker-compose nao esta instalado (nem o plugin 'docker compose')."
    }
}

function Invoke-Compose([string]$composeCmd, [string[]]$composeArgs) {
    # Supports both "docker-compose" and "docker compose"
    if ($composeCmd -eq "docker-compose") {
        docker-compose @composeArgs
    } elseif ($composeCmd -eq "docker compose") {
        docker compose @composeArgs
    } else {
        throw "Comando compose desconhecido: $composeCmd"
    }
    if ($LASTEXITCODE -ne 0) { throw "Falha ao executar docker compose: $($composeArgs -join ' ')" }
}

function Test-HyperfHttp {
    try {
        $resp = Invoke-WebRequest -Uri "http://localhost:9501/" -UseBasicParsing -TimeoutSec 2
        return ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500)
    } catch {
        return $false
    }
}

function Invoke-NativeOk([scriptblock]$fn) {
    & $fn
    return ($LASTEXITCODE -eq 0)
}

Write-Host "=========================================="
Write-Host "Iniciando Projeto - Saque PIX TecnoFit"
Write-Host "=========================================="
Write-Host ""

# Ir para o diretório do script
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptDir

# Pré-requisitos
Write-Info "Verificando pré-requisitos..."
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Err "docker não está instalado."
    exit 1
}
Write-Ok "docker encontrado"

$composeCmd = $null
try {
    $composeCmd = Get-DockerComposeCommand
    Write-Ok ("docker-compose detectado: " + $composeCmd)
} catch {
    Write-Err $_
    exit 1
}
Write-Host ""

foreach ($f in @("docker-compose.yml","Dockerfile","composer.json")) {
    if (-not (Test-Path $f)) {
    Write-Err "Arquivo essencial nao encontrado: $f"
        Write-Info "Diretório atual: $(Get-Location)"
        exit 1
    }
}

try {
    docker info | Out-Null
} catch {
    Write-Err "Docker nao esta acessivel. Confirme que o Docker Desktop esta rodando."
    exit 1
}

# Build se imagem não existir
Write-Info "Verificando imagem Docker..."
$hasImage = (docker images --format "{{.Repository}}:{{.Tag}}" | Select-String -SimpleMatch "hyperf-skeleton:latest")
if (-not $hasImage) {
    Write-Warn "Imagem nao encontrada, construindo (no-cache)..."
Invoke-Compose $composeCmd @("build","--no-cache")
} else {
    Write-Ok "Imagem já existe"
}
Write-Host ""

# Subir serviços
Write-Info "Iniciando servicos..."
Invoke-Compose $composeCmd @("up","-d")
Write-Host ""

Write-Info "Aguardando containers iniciarem (10s)..."
Start-Sleep -Seconds 10

# Verificar containers
Write-Info "Verificando containers..."
$ps = docker ps --format "{{.Names}}"
if (-not ($ps | Select-String -SimpleMatch "hyperf-skeleton")) {
    Write-Err "Container hyperf-skeleton nao esta rodando."
    Write-Host "Logs:" -ForegroundColor Yellow
    try { docker logs hyperf-skeleton --tail 40 2>&1 } catch { }
    exit 1
}
Write-Ok "Container hyperf-skeleton está rodando"

if (-not ($ps | Select-String -SimpleMatch "hyperf-mysql")) {
    Write-Err "Container hyperf-mysql não está rodando."
    exit 1
}
Write-Ok "Container hyperf-mysql está rodando"

if (-not ($ps | Select-String -SimpleMatch "hyperf-mailhog")) {
    Write-Err "Container hyperf-mailhog não está rodando."
    exit 1
}
Write-Ok "Container hyperf-mailhog está rodando"
Write-Host ""

# Aguardar MySQL ficar pronto
Write-Info "Aguardando MySQL estar pronto..."
$mysqlReady = $false
for ($i = 1; $i -le 60; $i++) {
    $oldEap = $ErrorActionPreference
    $ErrorActionPreference = "SilentlyContinue"
    $okPing = Invoke-NativeOk { docker exec hyperf-mysql mysqladmin ping -h 127.0.0.1 -uroot -proot --silent 1>$null 2>$null }
    if (-not $okPing) { Start-Sleep -Seconds 1; continue }

    $okQuery = Invoke-NativeOk { docker exec hyperf-mysql mysql -h 127.0.0.1 -uroot -proot -e "SELECT 1;" 1>$null 2>$null }
    $ErrorActionPreference = $oldEap
    if (-not $okQuery) { Start-Sleep -Seconds 1; continue }

    $mysqlReady = $true
    break
}
if (-not $mysqlReady) {
    Write-Err "MySQL nao ficou pronto a tempo."
    Write-Host "Ultimos logs do MySQL:" -ForegroundColor Yellow
    docker logs hyperf-mysql --tail 40 2>&1
    exit 1
}
Write-Ok "MySQL esta pronto"
Write-Host ""

# Verificar dependências (vendor)
Write-Info "Verificando dependencias (vendor)..."
$vendorExists = $false
try {
    docker exec hyperf-skeleton sh -c "test -f /opt/www/vendor/autoload.php" | Out-Null
    $vendorExists = $true
} catch {
    $vendorExists = $false
}
if (-not $vendorExists) {
    Write-Warn "Dependencias nao encontradas, instalando (composer install)..."
    docker exec hyperf-skeleton composer install --no-dev -o --no-interaction
    if ($LASTEXITCODE -ne 0) { throw "Erro ao instalar dependencias." }
    Write-Ok "Dependencias instaladas"
} else {
    Write-Ok "Dependencias ja instaladas"
}
Write-Host ""

# Migrations
Write-Info "Executando migrations..."
$tablesExist = ""
if ($mysqlReady) {
    $oldEap = $ErrorActionPreference
    $ErrorActionPreference = "SilentlyContinue"
    $tablesExist = docker exec hyperf-mysql mysql -h 127.0.0.1 -uroot -proot hyperf -N -e "SHOW TABLES LIKE 'account';" 2>$null
    $ErrorActionPreference = $oldEap
    if ($LASTEXITCODE -ne 0) { $tablesExist = "" }
}
if ($tablesExist -and $tablesExist.Trim().Length -gt 0) {
    Write-Ok "Tabelas ja existem, pulando migrations"
} else {
    Write-Warn "Criando tabelas..."
    Write-Warn "Executando migrations.sql..."
    if (-not (Test-Path "migrations.sql")) { throw "migrations.sql nao encontrado." }

    $migrationOk = $false
    for ($retry = 1; $retry -le 3; $retry++) {
        $oldEap = $ErrorActionPreference
        $ErrorActionPreference = "SilentlyContinue"
        Get-Content -Raw "migrations.sql" | docker exec -i hyperf-mysql mysql -h 127.0.0.1 -uroot -proot hyperf 1>$null 2>$null
        $ErrorActionPreference = $oldEap
        if ($LASTEXITCODE -eq 0) { $migrationOk = $true; break }
        if ($retry -lt 3) {
            Write-Warn "Tentativa $retry falhou, aguardando 3s..."
            Start-Sleep -Seconds 3
        }
    }
    if (-not $migrationOk) { throw "Erro ao executar migrations.sql apos 3 tentativas." }
    Write-Ok "Migrations executadas via SQL"
}
Write-Host ""

# Criar conta de teste
Write-Info "Criando conta de teste..."
$accountExists = "0"
$oldEap = $ErrorActionPreference
$ErrorActionPreference = "SilentlyContinue"
$accountExists = docker exec hyperf-mysql mysql -h 127.0.0.1 -uroot -proot hyperf -N -e "SELECT COUNT(*) FROM account WHERE name = 'Joao Silva';" 2>$null
$ErrorActionPreference = $oldEap
if ($LASTEXITCODE -ne 0 -or -not $accountExists) { $accountExists = "0" }

$accountId = ""
if (-not $accountExists -or $accountExists.Trim() -eq "0") {
    $oldEap = $ErrorActionPreference
    $ErrorActionPreference = "SilentlyContinue"
    $out = docker exec hyperf-mysql mysql -h 127.0.0.1 -uroot -proot hyperf -N -e "INSERT INTO account (id, name, balance, created_at, updated_at) VALUES (UUID(), 'Joao Silva', 1000.00, NOW(), NOW()); SELECT id FROM account WHERE name = 'Joao Silva' ORDER BY created_at DESC LIMIT 1;" 2>$null
    $ErrorActionPreference = $oldEap
    if ($LASTEXITCODE -eq 0 -and $out) {
        # Extrai UUID
        $match = [regex]::Match($out, "[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}", [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
        if ($match.Success) { $accountId = $match.Value }
    }
    if ($accountId) {
        Write-Ok "Conta de teste criada: $accountId"
    } else {
        Write-Warn "Nao foi possivel capturar o UUID automaticamente. Liste as contas com: docker exec -it hyperf-mysql mysql -uroot -proot hyperf -e \"SELECT id, name, balance FROM account;\""
    }
} else {
    try {
        $oldEap = $ErrorActionPreference
        $ErrorActionPreference = "SilentlyContinue"
        $accountId = (docker exec hyperf-mysql mysql -h 127.0.0.1 -uroot -proot hyperf -N -e "SELECT id FROM account WHERE name = 'Joao Silva' LIMIT 1;" 2>$null).Trim()
        $ErrorActionPreference = $oldEap
    } catch {
        $accountId = ""
    }
    if ($accountId) {
        Write-Ok "Conta de teste ja existe: $accountId"
    } else {
        Write-Ok "Conta de teste ja existe"
    }
}
Write-Host ""

# Aguardar Hyperf responder
Write-Info "Aguardando Hyperf iniciar completamente..."
$responding = $false
for ($i = 1; $i -le 30; $i++) {
    if (Test-HyperfHttp) { $responding = $true; break }
    Start-Sleep -Seconds 2
}
if ($responding) {
    Write-Ok "Hyferf esta respondendo"
} else {
    Write-Warn "Hyferf ainda nao respondeu. Verifique logs: docker logs hyperf-skeleton"
}
Write-Host ""

Write-Host "=========================================="
Write-Ok "Projeto iniciado com sucesso!"
Write-Host "=========================================="
Write-Host ""
Write-Host "Acessos:"
Write-Host "  - API: http://localhost:9501"
Write-Host "  - Mailhog UI: http://localhost:8025"
Write-Host "  - MySQL: localhost:3306"
Write-Host ""
Write-Host "Comandos uteis:"
Write-Host ("  - Ver logs: docker logs hyperf-skeleton -f")
Write-Host ("  - Parar: " + $composeCmd + " down")
Write-Host ("  - Reiniciar: " + $composeCmd + " restart")
Write-Host ""
Write-Host "Exemplo no Postman ou Insomnia!"
Write-Host ""
if ($accountId) {
    Write-Host "ID do usuário para teste: $accountId"
}
Write-Host "Exemplo de requisicao:"
if ($accountId) {
    Write-Host "  POST http://localhost:9501/account/$accountId/balance/withdraw"
} else {
    Write-Host "  POST http://localhost:9501/account/{accountId}/balance/withdraw"
}
Write-Host ""

