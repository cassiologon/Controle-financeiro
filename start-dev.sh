#!/bin/bash

# Cores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 Iniciando servidores de desenvolvimento...${NC}"
echo ""

# Função para limpar processos ao sair
cleanup() {
    echo ""
    echo -e "${YELLOW}⏹️  Parando servidores...${NC}"
    if [ ! -z "$API_PID" ]; then
        kill $API_PID 2>/dev/null
        pkill -P $API_PID 2>/dev/null
    fi
    if [ ! -z "$QUEUE_PID" ]; then
        kill $QUEUE_PID 2>/dev/null
        pkill -P $QUEUE_PID 2>/dev/null
    fi
    if [ ! -z "$APP_PID" ]; then
        kill $APP_PID 2>/dev/null
        pkill -P $APP_PID 2>/dev/null
    fi
    echo -e "${GREEN}✅ Servidores parados${NC}"
    exit
}

# Captura Ctrl+C
trap cleanup SIGINT SIGTERM

# Inicia API (Laravel)
echo -e "${CYAN}📡 Iniciando API Laravel (porta 8000)...${NC}"
cd API
php artisan serve &
API_PID=$!
cd ..

# Aguarda um pouco para garantir que a API iniciou
sleep 2

# Verifica se a API iniciou corretamente
if ! kill -0 $API_PID 2>/dev/null; then
    echo -e "${RED}❌ Erro ao iniciar API Laravel${NC}"
    exit 1
fi

# Inicia worker de filas (Laravel)
echo -e "${CYAN}📬 Iniciando worker de filas...${NC}"
cd API
php artisan queue:work &
QUEUE_PID=$!
cd ..

# Aguarda um pouco para o worker iniciar
sleep 1

# Verifica se o worker iniciou
if ! kill -0 $QUEUE_PID 2>/dev/null; then
    echo -e "${YELLOW}⚠️  Worker de filas não iniciou (verifique QUEUE_CONNECTION no .env)${NC}"
fi

# Inicia APP (Vue.js)
echo -e "${MAGENTA}🎨 Iniciando APP Vue.js (porta 3000)...${NC}"
cd APP
npm run dev &
APP_PID=$!
cd ..

# Aguarda um pouco para garantir que o APP iniciou
sleep 3

# Verifica se o APP iniciou corretamente
if ! kill -0 $APP_PID 2>/dev/null; then
    echo -e "${RED}❌ Erro ao iniciar APP Vue.js${NC}"
    cleanup
    exit 1
fi

echo ""
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Servidores iniciados com sucesso!${NC}"
echo -e "${CYAN}   📡 API:   http://localhost:8000${NC}"
echo -e "${CYAN}   📬 Filas: worker ativo (importação de faturas)${NC}"
echo -e "${MAGENTA}   🎨 APP:   http://localhost:3000${NC}"
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}Pressione Ctrl+C para parar os servidores${NC}"
echo ""
echo -e "${BLUE}Logs dos servidores aparecerão abaixo:${NC}"
echo ""

# Mantém o script rodando e monitora os processos
while kill -0 $API_PID 2>/dev/null || kill -0 $APP_PID 2>/dev/null; do
    sleep 1
    # Verifica se algum processo morreu
    if ! kill -0 $API_PID 2>/dev/null && [ ! -z "$API_PID" ]; then
        echo -e "${RED}⚠️  API Laravel parou inesperadamente${NC}"
        break
    fi
    if [ ! -z "$QUEUE_PID" ] && ! kill -0 $QUEUE_PID 2>/dev/null; then
        echo -e "${YELLOW}⚠️  Worker de filas parou${NC}"
    fi
    if ! kill -0 $APP_PID 2>/dev/null && [ ! -z "$APP_PID" ]; then
        echo -e "${RED}⚠️  APP Vue.js parou inesperadamente${NC}"
        break
    fi
done

# Limpa processos
cleanup
