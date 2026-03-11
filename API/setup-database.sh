#!/bin/bash

echo "=== Configuração do Banco de Dados ==="
echo ""
echo "Este script irá ajudá-lo a configurar o PostgreSQL para o sistema."
echo ""

# Verificar se PostgreSQL está instalado
if ! command -v psql &> /dev/null; then
    echo "PostgreSQL não está instalado."
    echo "Para instalar, execute:"
    echo "  sudo apt-get update"
    echo "  sudo apt-get install postgresql postgresql-contrib"
    echo ""
    exit 1
fi

# Verificar se o serviço está rodando
if ! sudo systemctl is-active --quiet postgresql; then
    echo "Iniciando o serviço PostgreSQL..."
    sudo systemctl start postgresql
    sudo systemctl enable postgresql
fi

echo "PostgreSQL está rodando!"
echo ""
echo "Agora vamos criar o banco de dados..."
echo ""

# Criar banco de dados
sudo -u postgres psql <<EOF
-- Verificar se o banco já existe
SELECT 1 FROM pg_database WHERE datname = 'controle_financeiro' \gexec

-- Se não existir, criar
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_database WHERE datname = 'controle_financeiro') THEN
        CREATE DATABASE controle_financeiro;
        RAISE NOTICE 'Banco de dados criado com sucesso!';
    ELSE
        RAISE NOTICE 'Banco de dados já existe.';
    END IF;
END
\$\$;
\q
EOF

echo ""
echo "Banco de dados configurado!"
echo ""
echo "Agora execute as migrations:"
echo "  cd API"
echo "  php artisan migrate"
echo ""

