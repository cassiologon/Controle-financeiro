#!/bin/bash
# Script para instalar dependências necessárias para extração PDF com OCR

set -e

echo "🔧 Instalando dependências para extração PDF com OCR..."
echo ""

# Verifica se está rodando como root para algumas instalações
if [ "$EUID" -ne 0 ]; then 
    SUDO="sudo"
else
    SUDO=""
fi

# 1. Instalar Python 3 e pip
echo "📦 Verificando Python 3..."
if ! command -v python3 &> /dev/null; then
    echo "Instalando Python 3..."
    $SUDO apt-get update
    $SUDO apt-get install -y python3 python3-pip
else
    echo "✓ Python 3 já instalado: $(python3 --version)"
fi

# 2. Instalar Tesseract OCR com idioma português
echo ""
echo "📦 Instalando Tesseract OCR..."
if ! command -v tesseract &> /dev/null; then
    echo "Instalando Tesseract OCR..."
    $SUDO apt-get update
    $SUDO apt-get install -y tesseract-ocr tesseract-ocr-por
else
    echo "✓ Tesseract já instalado: $(tesseract --version | head -1)"
fi

# Verifica se idioma português está disponível
if ! tesseract --list-langs | grep -q "por"; then
    echo "Instalando pacote de idioma português para Tesseract..."
    $SUDO apt-get install -y tesseract-ocr-por
fi

# 3. Instalar poppler-utils (necessário para pdf2image)
echo ""
echo "📦 Instalando poppler-utils..."
if ! command -v pdftoppm &> /dev/null; then
    echo "Instalando poppler-utils..."
    $SUDO apt-get update
    $SUDO apt-get install -y poppler-utils
else
    echo "✓ poppler-utils já instalado"
fi

# 4. Instalar dependências Python
echo ""
echo "📦 Instalando dependências Python..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Cria ambiente virtual se não existir
if [ ! -d "venv" ]; then
    echo "Criando ambiente virtual Python..."
    python3 -m venv venv
fi

# Ativa ambiente virtual e instala dependências
echo "Ativando ambiente virtual e instalando dependências..."
source venv/bin/activate

if [ -f "requirements.txt" ]; then
    pip install -r requirements.txt
    echo "✓ Dependências Python instaladas no ambiente virtual"
else
    echo "⚠️  Arquivo requirements.txt não encontrado"
    echo "Instalando dependências manualmente..."
    pip install pdf2image pytesseract Pillow opencv-python
fi

deactivate

echo ""
echo "✅ Instalação concluída!"
echo ""
echo "Para testar, execute:"
echo "  python3 scripts/extract_pdf_transactions.py pdf_251230134826.pdf"

