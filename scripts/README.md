# Scripts de Extração PDF com OCR

Este diretório contém scripts Python para extrair transações de PDFs usando OCR (Tesseract).

## Instalação

Execute o script de instalação:

```bash
bash scripts/install-python-ocr.sh
```

Este script irá:
1. Verificar/instalar Python 3
2. Instalar Tesseract OCR com idioma português
3. Instalar poppler-utils (necessário para pdf2image)
4. Criar ambiente virtual Python e instalar dependências

**Nota**: Algumas instalações requerem privilégios de administrador (sudo).

## Uso

### Via PHP (automático)

O sistema PHP tentará usar o Python OCR automaticamente quando disponível:

```php
$parser = new InvoiceParserService();
$transactions = $parser->parsePdf('caminho/para/arquivo.pdf');
```

### Via linha de comando

```bash
# Usando ambiente virtual (recomendado)
scripts/venv/bin/python scripts/extract_pdf_transactions.py pdf_251230134826.pdf

# Ou usando python3 diretamente (se dependências instaladas globalmente)
python3 scripts/extract_pdf_transactions.py pdf_251230134826.pdf
```

### Teste

```bash
python3 scripts/test_pdf_extraction.py pdf_251230134826.pdf
```

## Estrutura

- `extract_pdf_transactions.py` - Script principal de extração
- `test_pdf_extraction.py` - Script de teste
- `requirements.txt` - Dependências Python
- `install-python-ocr.sh` - Script de instalação
- `venv/` - Ambiente virtual Python (criado após instalação)

## Dependências

- Python 3.8+
- Tesseract OCR com idioma português
- poppler-utils
- pdf2image
- pytesseract
- Pillow
- opencv-python (opcional)

## Fallback

O sistema PHP usa a seguinte ordem de fallback:

1. **Python OCR** (mais preciso, mas mais lento)
2. **pdftotext** (rápido, texto limpo)
3. **smalot/pdfparser** (fallback final)

Se o Python OCR não estiver disponível, o sistema automaticamente usa os métodos alternativos.

