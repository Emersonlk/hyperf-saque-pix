#!/bin/sh

# Script de inicialização do container
# Verifica e instala dependências antes de iniciar o Hyperf

set -e

cd /opt/www

# Verificar se vendor/ existe e está completo
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 vendor/ não encontrado, instalando dependências..."
    composer install --no-dev -o
    echo "✅ Dependências instaladas"
fi

# Iniciar Hyperf
exec php /opt/www/bin/hyperf.php start
