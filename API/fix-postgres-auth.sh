#!/bin/bash

echo "=== Configurando autenticação do PostgreSQL ==="
echo ""

# Encontrar o arquivo pg_hba.conf
PG_HBA=$(sudo find /etc/postgresql -name "pg_hba.conf" 2>/dev/null | head -1)

if [ -z "$PG_HBA" ]; then
    echo "Erro: Não foi possível encontrar o arquivo pg_hba.conf"
    exit 1
fi

echo "Arquivo encontrado: $PG_HBA"
echo ""

# Fazer backup
sudo cp "$PG_HBA" "${PG_HBA}.backup"
echo "Backup criado: ${PG_HBA}.backup"
echo ""

# Configurar autenticação local para trust
echo "Configurando autenticação local..."
sudo sed -i 's/^host.*127\.0\.0\.1.*md5/host    all             all             127.0.0.1\/32            trust/' "$PG_HBA"
sudo sed -i 's/^host.*127\.0\.0\.1.*scram-sha-256/host    all             all             127.0.0.1\/32            trust/' "$PG_HBA"

# Adicionar linha se não existir
if ! grep -q "127.0.0.1/32.*trust" "$PG_HBA"; then
    echo "host    all             all             127.0.0.1/32            trust" | sudo tee -a "$PG_HBA" > /dev/null
fi

# Recarregar configuração do PostgreSQL
echo "Recarregando configuração do PostgreSQL..."
sudo systemctl reload postgresql

echo ""
echo "Configuração concluída!"
echo "Agora você pode executar: php artisan migrate"

