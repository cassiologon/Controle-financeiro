-- Script para criar o banco de dados
-- Execute com: sudo -u postgres psql < create-database.sql

-- Criar banco de dados se não existir
SELECT 'CREATE DATABASE controle_financeiro'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'controle_financeiro')\gexec

-- Criar usuário se não existir (opcional - usar postgres mesmo)
-- CREATE USER controle_user WITH PASSWORD 'senha_segura';
-- GRANT ALL PRIVILEGES ON DATABASE controle_financeiro TO controle_user;

