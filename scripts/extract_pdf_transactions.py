#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para extrair transações de faturas PDF do Mercado Pago usando OCR

Uso:
    python3 extract_pdf_transactions.py <caminho_do_pdf>

Ou se usando ambiente virtual:
    scripts/venv/bin/python extract_pdf_transactions.py <caminho_do_pdf>
"""

import sys
import json
import re
import unicodedata
from datetime import datetime
from pathlib import Path

try:
    from pdf2image import convert_from_path
    import pytesseract
    from PIL import Image
except ImportError as e:
    print(json.dumps({
        "error": f"Dependências não instaladas: {str(e)}",
        "transactions": []
    }), file=sys.stderr)
    sys.exit(1)


def normalize_text(text):
    """Normalização agressiva do texto OCR"""
    if not text:
        return ""
    
    # Converte para UTF-8 limpo
    if isinstance(text, bytes):
        text = text.decode('utf-8', errors='ignore')
    
    # Remove caracteres não imprimíveis (exceto espaços, quebras de linha e caracteres comuns)
    text = re.sub(r'[^\x20-\x7E\n\r\u00A0-\uFFFF]', '', text)
    
    # Normaliza quebras de linha e espaços
    text = re.sub(r'\r\n', '\n', text)
    text = re.sub(r'\r', '\n', text)
    text = re.sub(r'\n+', '\n', text)
    text = re.sub(r'[ \t]+', ' ', text)
    
    # Substitui variações de R$ (R4, RS, R§, etc) por R$
    text = re.sub(r'R[4S§$]\s*', 'R$ ', text, flags=re.IGNORECASE)
    text = re.sub(r'R\s*\$', 'R$', text)
    
    # Correções específicas de OCR conhecidas
    corrections = {
        'õata': 'Data',
        'bovimentaçzes': 'Movimentações',
        'õL': 'DL',
        'yólipaG': '*Alipay',
        'bóZóN': 'MAGAZINE',
        'FUE LB': 'LU',
        'QOBTF=BE': 'BOUTIQUE',
        'bOõó': 'MODA',
        'õó+FTFy': 'DAFITI',
        'bERCóõOLFVRE': 'MERCADOLIVRE',
        'õFZFTóLOCEóUJCOb': 'DIGITALOCEAN.COM',
        'PZ yUBVEb': 'PG',
        'óRFSTOCRóCKCR': 'NUVEM ARISTOCRACYC',
        'bPybELFbóFS': 'MP*MELIMAIS',
        '3PROõBTOS': '3PRODUTOS',
        '8óbFLEbóHE': 'JAMILEMAKE',
        'ESCBTóOVEFO': 'ESCUTAOVEIO',
        '+LóSWCObPRóS': 'FLASHCOMPRAS',
        'yLO8ó PHTSWOP': '*LOJA PKTSHOP',
        'CBRSOR, óF POàEREõ FõE': 'CURSOR, AI POWERED IDE',
        'bERCEóRFó bóTWEBS': 'MERCEARIA MATHEUS',
        'SWOPEE yUorthStar': 'SHOPEE *NorthStar',
        'bPyLO8óVESTFS': 'MP*LOJAVESTIS',
        'SERVFCOS CLóyCLóR': 'SERVICOS CLA*CLAR',
        '8FbJCOb': 'JIM.COM',
        'SWOPEE yQOóbóFS': 'SHOPEE *BOAMAIS',
    }
    
    for corrupted, correct in corrections.items():
        text = text.replace(corrupted, correct)
    
    # Normaliza caracteres numéricos corrompidos em contexto de valores monetários
    # O → 0 (apenas quando seguido de números ou antes de vírgula/ponto)
    text = re.sub(r'([R$]\s*)([O])(\d)', r'\g<1>0\3', text, flags=re.IGNORECASE)
    text = re.sub(r'(\d)([O])([,\.])', r'\g<1>0\3', text, flags=re.IGNORECASE)
    
    # l, I → 1 (em contexto numérico)
    text = re.sub(r'([R$]\s*)([lI])(\d)', r'\g<1>1\3', text, flags=re.IGNORECASE)
    text = re.sub(r'(\d)([lI])(\d)', r'\g<1>1\3', text, flags=re.IGNORECASE)
    
    # S → 5 (em contexto numérico)
    text = re.sub(r'([R$]\s*)([S])(\d)', r'\g<1>5\3', text, flags=re.IGNORECASE)
    text = re.sub(r'(\d)([S])(\d)', r'\g<1>5\3', text, flags=re.IGNORECASE)
    
    # B → 8 (em contexto numérico)
    text = re.sub(r'([R$]\s*)([B])(\d)', r'\g<1>8\3', text, flags=re.IGNORECASE)
    text = re.sub(r'(\d)([B])(\d)', r'\g<1>8\3', text, flags=re.IGNORECASE)
    
    # Remove espaços múltiplos finais
    text = re.sub(r'\s+', ' ', text)
    text = text.strip()
    
    return text


def normalize_amount(amount_str):
    """Normaliza valor monetário brasileiro"""
    if not amount_str:
        return None
    
    # Remove R$ e espaços
    amount_str = re.sub(r'R\$\s*', '', amount_str.strip())
    
    # Remove caracteres não numéricos exceto vírgula e ponto
    amount_str = re.sub(r'[^\d,.]', '', amount_str)
    
    if not amount_str:
        return None
    
    # Detecta formato brasileiro (vírgula como separador decimal)
    if ',' in amount_str:
        # Formato brasileiro: 1.234,56 ou 123,45
        parts = amount_str.split(',')
        if len(parts) == 2:
            integer_part = parts[0].replace('.', '')
            decimal_part = parts[1][:2]  # Limita a 2 dígitos
            try:
                return float(f"{integer_part}.{decimal_part}")
            except ValueError:
                return None
    
    # Se tem ponto mas não vírgula, pode ser formato americano
    if '.' in amount_str and ',' not in amount_str:
        parts = amount_str.split('.')
        if len(parts) == 2 and len(parts[1]) == 2:
            # Provavelmente formato americano, converte
            try:
                return float(amount_str)
            except ValueError:
                return None
    
    # Tenta parsear como float direto
    try:
        # Se não tem separador decimal, assume que últimos 2 dígitos são centavos
        if '.' not in amount_str and ',' not in amount_str:
            if len(amount_str) >= 2:
                return float(amount_str[:-2] + '.' + amount_str[-2:])
        return float(amount_str.replace(',', '.'))
    except ValueError:
        return None


def parse_date(date_str, invoice_year=None):
    """Converte data DD/MM ou DD/MM/YYYY para formato YYYY-MM-DD"""
    if not date_str:
        return None
    
    # Normaliza data corrompida
    date_str = date_str.replace('M', '4').replace('%', '9')
    date_str = re.sub(r'(\d)4/', r'\g<1>4/', date_str)
    date_str = re.sub(r'(\d)9/', r'\g<1>9/', date_str)
    
    # Padrão DD/MM/YYYY
    match = re.match(r'(\d{2})/(\d{2})/(\d{4})', date_str)
    if match:
        day, month, year = match.groups()
        try:
            datetime(int(year), int(month), int(day))
            return f"{year}-{month}-{day}"
        except ValueError:
            pass
    
    # Padrão DD/MM (usa ano da fatura)
    match = re.match(r'(\d{2})/(\d{2})', date_str)
    if match:
        day, month = match.groups()
        year = invoice_year or datetime.now().year
        
        # Se mês é maior que mês atual, provavelmente é do ano anterior
        current_month = datetime.now().month
        if int(month) > current_month and int(month) <= 12:
            year -= 1
        
        try:
            datetime(int(year), int(month), int(day))
            return f"{year}-{month}-{day}"
        except ValueError:
            pass
    
    return None


def extract_invoice_year(text):
    """Extrai o ano da fatura do texto"""
    # Tenta encontrar período da fatura
    match = re.search(r'Consumos de \d{2}/\d{2}/(\d{4})', text)
    if match:
        return int(match.group(1))
    
    # Tenta encontrar data de vencimento
    match = re.search(r'Vence em \d{2}/\d{2}/(\d{4})', text)
    if match:
        return int(match.group(1))
    
    # Tenta encontrar data de emissão
    match = re.search(r'Emitido em[:\s]+\d{2}/\d{2}/(\d{4})', text)
    if match:
        return int(match.group(1))
    
    # Tenta encontrar qualquer data completa
    match = re.search(r'\d{2}/\d{2}/(\d{4})', text)
    if match:
        year = int(match.group(1))
        if 2020 <= year <= 2030:
            return year
    
    return datetime.now().year


def extract_invoice_year_from_words(words):
    """Extrai o ano da fatura das palavras com coordenadas"""
    # Constrói texto a partir das palavras
    text = ' '.join(w['text'] for w in words)
    return extract_invoice_year(text)


def normalize_text_for_filtering(text):
    """Normaliza texto para filtragem semântica (lowercase, remove acentos)"""
    if not text:
        return ""
    # Remove acentos
    text = unicodedata.normalize('NFD', text.lower())
    text = ''.join(char for char in text if unicodedata.category(char) != 'Mn')
    return text.strip()


def is_summary_or_total(description: str) -> bool:
    """Verifica se a descrição indica um total/resumo não transacional"""
    if not description:
        return False
    
    normalized = normalize_text_for_filtering(description)
    
    # Palavras-chave que indicam totais/resumos
    summary_keywords = [
        'total',
        'valor minimo',
        'minimo',
        'fatura',
        'resumo',
        'pagamento da fatura',
        'creditos devolvidos',
        'pagamentos creditos devolvidos',
        'saldo',
        'limite',
        'encerramento',
        'vencimento',
        'emitido',
        'consumos de',
        'movimentacoes',
        'do aplicativo',
        'que pagar',
        'você deve',
        'o valor minimo',
        'da fatura de',
        'concedido',
        'opcoes de parcelamento',
        'escolha melhor',
        'cet',
        'rotativo'
    ]
    
    for keyword in summary_keywords:
        if keyword in normalized:
            return True
    
    return False


def extract_description_horizontal(line, value_x, value_y, all_words):
    """Busca descrição horizontalmente na mesma linha, à esquerda do valor"""
    if not all_words:
        return None
    
    desc_parts = []
    for word in all_words:
        if not isinstance(word, dict):
            continue
        
        word_x = word.get('left', 0)
        word_y = word.get('top', 0)
        word_text = word.get('text', '').strip()
        
        # Mesma linha (|y_diff| ≤ 5px) e à esquerda do valor
        if abs(word_y - value_y) <= 5 and word_x < value_x:
            # Ignora textos muito curtos, datas, valores monetários
            if (len(word_text) >= 3 and 
                not re.match(r'^\d{2}/\d{2}$', word_text) and
                not extract_value_tolerant(word_text) and
                not re.match(r'^\d+$', word_text)):
                desc_parts.append(word_text)
    
    if desc_parts:
        description = ' '.join(desc_parts).strip()
        if len(description) >= 5:
            return description
    
    return None


def inherit_description_for_parcela(description, line_idx, classified_lines, current_y):
    """Herda descrição de transação anterior para parcelas"""
    if not description:
        return description
    
    # Verifica se é uma parcela sem nome de estabelecimento
    has_parcela = bool(re.search(r'Parcela\s+\d+\s+de\s+\d+', description, re.IGNORECASE))
    has_establishment = bool(re.search(r'[A-Z]{2,}|[A-Z]\w+', description))
    
    if has_parcela and not has_establishment:
        # Busca descrição válida acima (≤ 40px)
        for prev_idx in range(max(0, line_idx - 5), line_idx):
            prev_line = classified_lines[prev_idx]
            if not isinstance(prev_line, dict):
                continue
            
            prev_y = prev_line.get('top', 0)
            delta_y = abs(prev_y - current_y)
            
            if delta_y <= 40 and prev_y < current_y:
                prev_desc_words = prev_line.get('description', [])
                if prev_desc_words:
                    prev_desc_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in prev_desc_words)
                    prev_desc_text = prev_desc_text.strip()
                    
                    # Verifica se é uma descrição válida (não é parcela, não é muito curta)
                    if (prev_desc_text and len(prev_desc_text) >= 5 and
                        not re.search(r'Parcela\s+\d+\s+de\s+\d+', prev_desc_text, re.IGNORECASE) and
                        not is_summary_or_total(prev_desc_text)):
                        # Herda descrição e concatena com parcela
                        return f"{prev_desc_text} {description}"
    
    return description


def normalize_merchant_name(text: str) -> str:
    """Normaliza nome de estabelecimento, especialmente MERCADO LIVRE"""
    if not text:
        return text
    
    normalized = normalize_text_for_filtering(text)
    
    # Padrões OCR comuns para MERCADO LIVRE
    mercado_livre_patterns = [
        r'merca[do]*\s*livre',
        r'mercadolivre',
        r'mercadolvre',
        r'mcdolivre',
        r'mlivre',
        r'mercado\s*livre',
        r'merc\s*livre',
        r'mercado\s*livr',
        r'merc\s*livr',
        r'mercad\s*livre',
        r'mercad\s*livr'
    ]
    
    for pattern in mercado_livre_patterns:
        if re.search(pattern, normalized):
            return "MERCADOLIVRE"
    
    return text


def detect_mercadolivre_by_proximity(line, value_x, value_y, all_words, debug_normalization=None):
    """Detecta MERCADO LIVRE por proximidade visual (mesma linha ou até 15px acima, à esquerda do valor)"""
    if not all_words:
        return None
    
    for word in all_words:
        if not isinstance(word, dict):
            continue
        
        word_x = word.get('left', 0)
        word_y = word.get('top', 0)
        word_text = word.get('text', '').strip()
        
        # Verifica se está à esquerda do valor e na mesma linha ou até 15px acima
        if word_x < value_x and abs(word_y - value_y) <= 15:
            # Normaliza e verifica se é MERCADO LIVRE
            normalized = normalize_merchant_name(word_text)
            if normalized == "MERCADOLIVRE":
                if debug_normalization is not None:
                    debug_normalization.append({
                        'original_text': word_text,
                        'normalized_text': normalized,
                        'rule': 'proximity_visual',
                        'word_x': word_x,
                        'word_y': word_y,
                        'value_x': value_x,
                        'value_y': value_y
                    })
                return normalized
    
    return None


def inherit_mercadolivre_from_previous(description, line_idx, transactions, current_y, current_date, debug_normalization=None):
    """Herdar descrição MERCADO LIVRE de transação anterior se condições forem atendidas"""
    if not transactions:
        return description
    
    # Se já tem descrição válida, não precisa herdar
    if description and description.strip() and description != get_intelligent_fallback_description(0):
        return description
    
    # Busca na última transação criada
    if len(transactions) > 0:
        last_transaction = transactions[-1]
        last_desc = last_transaction.get('descricao', '')
        
        # Verifica se a última transação é MERCADO LIVRE
        normalized_last = normalize_merchant_name(last_desc)
        if normalized_last == "MERCADOLIVRE":
            # Verifica se tem mesma data
            last_date = last_transaction.get('data')
            if last_date == current_date:
                # Herda descrição
                if debug_normalization is not None:
                    debug_normalization.append({
                        'original_text': description or '',
                        'normalized_text': normalized_last,
                        'rule': 'inheritance_same_date',
                        'inherited_from': last_desc,
                        'current_date': current_date,
                        'last_date': last_date
                    })
                return normalized_last
    
    return description


def get_intelligent_fallback_description(value):
    """Retorna descrição fallback inteligente baseada no valor"""
    if value < 50:
        return "Compra valor baixo (OCR)"
    elif value <= 1000:
        return "Compra (OCR)"
    else:
        return "Pagamento / ajuste (OCR)"


def extract_value_tolerant(line_text):
    """Detecção tolerante de valor monetário"""
    # Aceita R$, R4, RS, R§
    value_patterns = [
        r'R[\$\§S4]\s*(\d{1,3}(?:\.\d{3})*,\d{2})',  # R$ 1.234,56
        r'R[\$\§S4]\s*(\d+,\d{2})',  # R$ 123,45
        r'R[\$\§S4]\s*(\d{1,3}(?:\.\d{3})*)',  # R$ 1.234
        r'R[\$\§S4]\s*(\d+)',  # R$ 123
    ]
    
    for pattern in value_patterns:
        match = re.search(pattern, line_text, re.IGNORECASE)
        if match:
            return normalize_amount(match.group(1))
    return None


def finalize_transaction(current_transaction, invoice_year, transactions, seen_hashes, debug_states=None):
    """Finaliza uma transação em construção se válida"""
    if not current_transaction or not current_transaction.get('date'):
        return None
    
    # Unir descrição de múltiplas linhas
    description = ' '.join(current_transaction.get('description_lines', []))
    description = normalize_text(description)
    
    # Remove data e valor da descrição
    description = re.sub(r'^\d{2}/\d{2}\s+', '', description)
    description = re.sub(r'\s*R[\$\§S4]\s*[\d\s\.,]+\s*$', '', description)
    description = re.sub(r'\s+', ' ', description.strip())
    
    # Valida descrição
    if len(description) < 5:
        return None
    
    # Ignora descrições que são apenas números
    if re.match(r'^\d+$', description.strip()):
        return None
    
    value = current_transaction.get('value')
    if not value or value <= 0 or value > 100000:
        return None
    
    # Parseia data
    parsed_date = parse_date(current_transaction['date'], invoice_year)
    if not parsed_date:
        return None
    
    # Verifica se há informação de parcela
    parcela_match = re.search(r'Parcela\s+(\d+)\s+de\s+(\d+)', description, re.IGNORECASE)
    if parcela_match:
        parcela_info = f" Parcela {parcela_match.group(1)} de {parcela_match.group(2)}"
        if parcela_info not in description:
            description += parcela_info
    
    # Cria hash para deduplicação
    desc_normalized = re.sub(r'\s+', ' ', description.lower().strip())
    transaction_hash = f"{parsed_date}|{value:.2f}|{desc_normalized[:50]}"
    
    if transaction_hash in seen_hashes:
        return None
    seen_hashes.add(transaction_hash)
    
    # Determina tipo de transação
    transaction_type = 'compra'
    if 'pagamento' in description.lower():
        transaction_type = 'pagamento'
    elif 'crédito' in description.lower() or 'credito' in description.lower():
        transaction_type = 'credito'
    
    transaction = {
        'data': parsed_date,
        'descricao': description,
        'valor': abs(value),
        'tipo': transaction_type
    }
    
    transactions.append(transaction)
    
    if debug_states is not None:
        debug_states.append({
            'transaction': transaction,
            'date': current_transaction['date'],
            'description_lines': current_transaction.get('description_lines', []),
            'value': value,
            'start_y': current_transaction.get('start_y'),
            'last_y': current_transaction.get('last_y'),
            'closed_reason': current_transaction.get('closed_reason', 'completed')
        })
    
    return transaction


def is_value_in_ignore_context_by_position(line_y, line_height, all_lines, line_idx):
    """Verifica se um valor deve ser ignorado baseado em POSIÇÃO e FONTE, não texto"""
    # Tornado muito mais permissivo: apenas ignora casos extremos
    
    # Ignora valores com fonte MUITO grande (height > 50px indica título/cabeçalho)
    # Mas só se estiver nas extremidades da página
    if line_height > 50:
        if line_idx < 3 or (all_lines and line_idx > len(all_lines) - 3):
            return True
    
    # Por padrão, NÃO ignora (valor é soberano)
    return False


def fuse_value_tokens(classified_lines, page_info=None, debug_fusion=None):
    """Funde tokens de valor monetário fragmentados em múltiplas linhas"""
    fused_values = []  # Lista de valores fundidos: {'value': float, 'y': int, 'x': float, 'line_idx': int, 'tokens': [...]}
    consumed_lines = set()  # Linhas já consumidas na fusão
    
    for line_idx, line in enumerate(classified_lines):
        if line_idx in consumed_lines:
            continue
        
        if not isinstance(line, dict):
            continue
        
        line_y = line.get('top', 0)
        value_words = line.get('value', [])
        
        if not value_words:
            continue
        
        # Constrói texto da coluna de valor
        value_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in value_words)
        
        # Verifica se tem símbolo R$ mas não tem número completo
        has_rs_symbol = bool(re.search(r'R[\$\§S4]', value_text, re.IGNORECASE))
        has_number = bool(re.search(r'\d+,\d{2}', value_text))
        
        # Se tem apenas símbolo R$ sem número, busca número nas linhas próximas
        if has_rs_symbol and not has_number:
            # Busca número até 20px acima ou abaixo, mesma coluna (±40px em X)
            value_x_avg = sum(w.get('left', 0) for w in value_words if isinstance(w, dict)) / len(value_words) if value_words else 0
            
            for search_idx in range(max(0, line_idx - 3), min(len(classified_lines), line_idx + 4)):
                if search_idx == line_idx or search_idx in consumed_lines:
                    continue
                
                search_line = classified_lines[search_idx]
                if not isinstance(search_line, dict):
                    continue
                
                search_y = search_line.get('top', 0)
                delta_y = abs(search_y - line_y)
                
                if delta_y > 20:  # Muito distante
                    continue
                
                search_value_words = search_line.get('value', [])
                if not search_value_words:
                    continue
                
                search_value_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in search_value_words)
                
                # Verifica se tem número mas não tem símbolo R$
                search_has_number = bool(re.search(r'\d+,\d{2}', search_value_text))
                search_has_rs = bool(re.search(r'R[\$\§S4]', search_value_text, re.IGNORECASE))
                
                if search_has_number and not search_has_rs:
                    # Verifica se está na mesma coluna (±40px em X)
                    search_x_avg = sum(w.get('left', 0) for w in search_value_words if isinstance(w, dict)) / len(search_value_words) if search_value_words else 0
                    delta_x = abs(search_x_avg - value_x_avg)
                    
                    if delta_x <= 40:
                        # Funde tokens
                        combined_text = f"{value_text} {search_value_text}"
                        fused_value = extract_value_tolerant(combined_text)
                        
                        if fused_value and fused_value > 0:
                            # Usa posição média
                            fused_y = (line_y + search_y) / 2
                            fused_x = (value_x_avg + search_x_avg) / 2
                            
                            fused_values.append({
                                'value': fused_value,
                                'y': int(fused_y),
                                'x': fused_x,
                                'line_idx': line_idx,
                                'tokens': [
                                    {'line_idx': line_idx, 'text': value_text, 'y': line_y, 'x': value_x_avg},
                                    {'line_idx': search_idx, 'text': search_value_text, 'y': search_y, 'x': search_x_avg}
                                ]
                            })
                            
                            consumed_lines.add(line_idx)
                            consumed_lines.add(search_idx)
                            
                            if debug_fusion is not None:
                                debug_fusion.append({
                                    'type': 'fused',
                                    'original_tokens': [
                                        {'line_idx': line_idx, 'text': value_text, 'y': line_y},
                                        {'line_idx': search_idx, 'text': search_value_text, 'y': search_y}
                                    ],
                                    'fused_value': fused_value,
                                    'fused_y': fused_y,
                                    'fused_x': fused_x
                                })
                            
                            break
        
        # Se tem número mas não tem símbolo R$, busca símbolo nas linhas próximas
        elif has_number and not has_rs_symbol:
            value_x_avg = sum(w.get('left', 0) for w in value_words if isinstance(w, dict)) / len(value_words) if value_words else 0
            
            for search_idx in range(max(0, line_idx - 3), min(len(classified_lines), line_idx + 4)):
                if search_idx == line_idx or search_idx in consumed_lines:
                    continue
                
                search_line = classified_lines[search_idx]
                if not isinstance(search_line, dict):
                    continue
                
                search_y = search_line.get('top', 0)
                delta_y = abs(search_y - line_y)
                
                if delta_y > 20:
                    continue
                
                search_value_words = search_line.get('value', [])
                if not search_value_words:
                    continue
                
                search_value_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in search_value_words)
                search_has_rs = bool(re.search(r'R[\$\§S4]', search_value_text, re.IGNORECASE))
                search_has_number = bool(re.search(r'\d+,\d{2}', search_value_text))
                
                if search_has_rs and not search_has_number:
                    search_x_avg = sum(w.get('left', 0) for w in search_value_words if isinstance(w, dict)) / len(search_value_words) if search_value_words else 0
                    delta_x = abs(search_x_avg - value_x_avg)
                    
                    if delta_x <= 40:
                        combined_text = f"{search_value_text} {value_text}"
                        fused_value = extract_value_tolerant(combined_text)
                        
                        if fused_value and fused_value > 0:
                            fused_y = (line_y + search_y) / 2
                            fused_x = (value_x_avg + search_x_avg) / 2
                            
                            fused_values.append({
                                'value': fused_value,
                                'y': int(fused_y),
                                'x': fused_x,
                                'line_idx': line_idx,
                                'tokens': [
                                    {'line_idx': search_idx, 'text': search_value_text, 'y': search_y, 'x': search_x_avg},
                                    {'line_idx': line_idx, 'text': value_text, 'y': line_y, 'x': value_x_avg}
                                ]
                            })
                            
                            consumed_lines.add(line_idx)
                            consumed_lines.add(search_idx)
                            
                            if debug_fusion is not None:
                                debug_fusion.append({
                                    'type': 'fused',
                                    'original_tokens': [
                                        {'line_idx': search_idx, 'text': search_value_text, 'y': search_y},
                                        {'line_idx': line_idx, 'text': value_text, 'y': line_y}
                                    ],
                                    'fused_value': fused_value,
                                    'fused_y': fused_y,
                                    'fused_x': fused_x
                                })
                            
                            break
        
        # Se tem valor completo (R$ + número), adiciona diretamente
        elif has_rs_symbol and has_number:
            value = extract_value_tolerant(value_text)
            if value and value > 0:
                value_x_avg = sum(w.get('left', 0) for w in value_words if isinstance(w, dict)) / len(value_words) if value_words else 0
                
                fused_values.append({
                    'value': value,
                    'y': line_y,
                    'x': value_x_avg,
                    'line_idx': line_idx,
                    'tokens': [
                        {'line_idx': line_idx, 'text': value_text, 'y': line_y, 'x': value_x_avg}
                    ]
                })
                
                consumed_lines.add(line_idx)
                
                if debug_fusion is not None:
                    debug_fusion.append({
                        'type': 'complete',
                        'original_tokens': [
                            {'line_idx': line_idx, 'text': value_text, 'y': line_y}
                        ],
                        'fused_value': value,
                        'fused_y': line_y,
                        'fused_x': value_x_avg
                    })
    
    return fused_values, consumed_lines


def extract_transactions_from_lines(classified_lines, invoice_year, debug_states=None, pending_debug=None, page_info=None, fused_values=None, consumed_lines=None):
    """Extrai transações: cada valor monetário fecha uma transação, herdando data mais recente"""
    transactions = []
    # Deduplicação baseada em posição espacial, não conteúdo
    seen_positions = set()  # (page_number, round_y, round_x)
    sequence_by_page = {}  # Contador sequencial por página
    
    # Se não há valores fundidos, processa normalmente
    if fused_values is None:
        fused_values = []
    if consumed_lines is None:
        consumed_lines = set()
    
    # Palavras-chave para ignorar linhas completamente
    ignore_keywords = [
        'Vencimento', 'Emitido', 'Consumos de', 'Movimentações',
        'Cartão Visa', 'Data Movimentações', 'Valor em R'
    ]
    
    # Contexto de data ativa (persistente até nova data aparecer)
    current_date_context = None
    
    # Linhas de descrição pendentes (acumuladas até encontrar valor)
    pending_description_lines = []
    pending_description_start_y = None
    
    # Debug: estados de pending descriptions
    if pending_debug is None:
        pending_debug = []
    
    # Primeiro, processa valores fundidos (cada valor fundido gera uma transação)
    for fv in fused_values:
        line_idx = fv['line_idx']
        value = fv['value']
        value_x = fv['x']
        value_y = fv['y']
        value_words = [t['text'] for t in fv['tokens']]
        
        # Obtém linha correspondente para extrair descrição e data
        line = classified_lines[line_idx] if line_idx < len(classified_lines) else None
        if not line or not isinstance(line, dict):
            continue
        
        line_y = value_y
        all_words = line.get('all_words', [])
        line_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in all_words)
        line_text_lower = line_text.lower()
        
        # Verifica palavras-chave de ignorar completamente
        if any(keyword.lower() in line_text_lower for keyword in ignore_keywords):
            continue
        
        # Extrai data da coluna DATA da linha
        data_words = line.get('data', [])
        date_str = None
        for word in data_words:
            if isinstance(word, dict):
                text = word.get('text', '')
                if re.match(r'^\d{2}/\d{2}$', text):
                    date_str = text
                    break
        
        # Se não encontrou data na linha, usa contexto atual
        if not date_str:
            date_str = current_date_context
        
        # Se encontrou data, atualiza contexto
        if date_str:
            current_date_context = date_str
        
        # Processa valor fundido (cria transação)
        if value and value > 0 and value <= 100000:
            # Calcula altura da linha (para detectar fontes grandes = títulos)
            line_height = max(w.get('height', 0) for w in all_words if isinstance(w, dict)) if all_words else 0
            
            # Verifica se valor deve ser ignorado baseado em POSIÇÃO e FONTE
            if is_value_in_ignore_context_by_position(line_y, line_height, classified_lines, line_idx):
                # Registra no debug mas ignora
                if pending_debug is not None:
                    pending_debug.append({
                        'value': value,
                        'line_y': line_y,
                        'line_height': line_height,
                        'rejected': True,
                        'rejection_reason': 'position_or_font'
                    })
                # Limpa descrições pendentes e continua
                pending_description_lines = []
                pending_description_start_y = None
                continue
            
            # REGRA DE OURO: Cada valor fecha uma transação
            # Usa data do contexto atual (pode ser None se não houver data ainda)
            transaction_date = current_date_context
            
            # Se não tem data no contexto, tenta buscar da linha atual ou anterior
            if not transaction_date:
                # Procura data nas linhas anteriores próximas (até 100px acima)
                for prev_idx in range(max(0, line_idx - 5), line_idx):
                    prev_line = classified_lines[prev_idx]
                    prev_y = prev_line.get('top', 0)
                    if abs(prev_y - line_y) <= 100:
                        prev_data_words = prev_line.get('data', [])
                        for word in prev_data_words:
                            if isinstance(word, dict):
                                text = word.get('text', '')
                                if re.match(r'^\d{2}/\d{2}$', text):
                                    transaction_date = text
                                    break
                        if transaction_date:
                            break
            
            # Se ainda não tem data, usa None (será tratado depois)
            # NÃO pula mais - valor é soberano
            
            # Constrói descrição das linhas pendentes
            description = ' '.join(pending_description_lines) if pending_description_lines else ''
            
            # Se não tem descrição pendente, tenta extrair da linha atual (sem o valor)
            if not description.strip():
                description_words = line.get('description', [])
                desc_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in description_words)
                # Remove o valor da descrição
                desc_text = re.sub(r'\s*R[\$\§S4]\s*[\d\s\.,]+\s*', '', desc_text, flags=re.IGNORECASE)
                description = desc_text.strip()
            
            # Se ainda não tem descrição, busca em linhas anteriores próximas (até 100px acima)
            if not description.strip():
                desc_lines_found = []
                for prev_idx in range(max(0, line_idx - 5), line_idx):
                    prev_line = classified_lines[prev_idx]
                    prev_y = prev_line.get('top', 0)
                    delta_y = abs(prev_y - line_y)
                    
                    # Se está próxima (até 100px acima)
                    if delta_y <= 100 and prev_y < line_y:
                        # Tenta extrair descrição da linha anterior
                        prev_desc_words = prev_line.get('description', [])
                        if prev_desc_words:
                            prev_desc_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in prev_desc_words)
                            prev_desc_text = prev_desc_text.strip()
                            
                            # Ignora se for muito curta, apenas números, ou data
                            if (prev_desc_text and len(prev_desc_text) >= 3 and 
                                not re.match(r'^\d+$', prev_desc_text) and 
                                not re.match(r'^\d{2}/\d{2}$', prev_desc_text)):
                                # Verifica se não é um valor monetário
                                if not extract_value_tolerant(prev_desc_text):
                                    desc_lines_found.append(prev_desc_text)
                        
                        # Também verifica all_words se não encontrou na coluna de descrição
                        elif prev_line.get('all_words'):
                            prev_all_words = prev_line.get('all_words', [])
                            prev_desc_parts = []
                            for word in prev_all_words:
                                if isinstance(word, dict):
                                    text = word.get('text', '')
                                    # Ignora datas e valores
                                    if (not re.match(r'^\d{2}/\d{2}$', text) and 
                                        not extract_value_tolerant(text) and
                                        text.strip()):
                                        word_left = word.get('left', 0)
                                        # Se está na região de descrição (não é data nem valor)
                                        if word_left > 400:
                                            prev_desc_parts.append(text)
                            
                            if prev_desc_parts:
                                prev_desc_text = ' '.join(prev_desc_parts).strip()
                                if (prev_desc_text and len(prev_desc_text) >= 3 and 
                                    not re.match(r'^\d+$', prev_desc_text)):
                                    if not extract_value_tolerant(prev_desc_text):
                                        desc_lines_found.append(prev_desc_text)
                
                # Usa todas as descrições encontradas (em ordem reversa para manter ordem cronológica)
                if desc_lines_found:
                    description = ' '.join(reversed(desc_lines_found))
            
            # Normaliza descrição
            description = normalize_text(description)
            description = re.sub(r'^\d{2}/\d{2}\s+', '', description)
            description = re.sub(r'\s*R[\$\§S4]\s*[\d\s\.,]+\s*$', '', description)
            description = re.sub(r'\s+', ' ', description.strip())
            
            # 3️⃣ Herança de descrição para parcelas
            description = inherit_description_for_parcela(description, line_idx, classified_lines, value_y)
            
            # 4️⃣ Fallback inteligente de descrição
            if not description.strip() or len(description.strip()) < 3:
                description = get_intelligent_fallback_description(value)
            elif description.strip() == "Transação sem descrição (OCR)":
                description = get_intelligent_fallback_description(value)
            
            # VALOR É SOBERANO: Sempre cria transação se valor foi detectado
            # Parseia data (pode ser None)
            parsed_date = None
            if transaction_date:
                parsed_date = parse_date(transaction_date, invoice_year)
            
            # SEMPRE cria transação, mesmo sem data parseada
            # Se não conseguiu parsear data, usa None mas ainda cria
            if not parsed_date:
                # Tenta usar data padrão (primeiro dia do mês da fatura)
                if invoice_year:
                    parsed_date = f"{invoice_year}-12-01"  # Usa dezembro como padrão se não conseguir parsear
                else:
                    parsed_date = None
            
            # Verifica se há informação de parcela
            parcela_match = re.search(r'Parcela\s+(\d+)\s+de\s+(\d+)', description, re.IGNORECASE)
            if parcela_match:
                parcela_info = f" Parcela {parcela_match.group(1)} de {parcela_match.group(2)}"
                if parcela_info not in description:
                    description += parcela_info
            
            # 1️⃣ Filtro semântico de valores não transacionais
            if is_summary_or_total(description):
                # Descarta transação se for total/resumo
                if pending_debug is not None:
                    pending_debug.append({
                        'date': transaction_date,
                        'description': description,
                        'value': value,
                        'line_y': line_y,
                        'line_x': value_x,
                        'accepted': False,
                        'rejection_reason': 'summary_or_total'
                    })
                # Limpa descrições pendentes e continua
                pending_description_lines = []
                pending_description_start_y = None
                continue
            
            # Deduplicação baseada em POSIÇÃO ESPACIAL, não conteúdo
            # value_x e value_y já foram calculados acima (do valor fundido ou da linha atual)
            
            # Obtém número da página (0-indexed)
            page_number = 0
            if page_info:
                # page_info é um dict mapeando line_idx -> page_number
                page_number = page_info.get(line_idx, 0)
            
            # Cria identificador de posição: (page_number, round(y/10), round(x/10))
            round_y = round(value_y / 10)
            round_x = round(value_x / 10)
            position_id = (page_number, round_y, round_x)
            
            # Verifica se já existe transação nesta posição exata
            is_duplicate = position_id in seen_positions
            
            # Deduplicação mínima: apenas ignora se mesma página, mesmo Y, mesmo valor, e OCR confidence muito baixa
            # Mas como estamos usando posição arredondada, isso já cobre casos muito próximos
            # Se não for duplicata por posição, sempre cria transação
            
            if not is_duplicate:
                seen_positions.add(position_id)
                
                # Incrementa sequence_id por página
                if page_number not in sequence_by_page:
                    sequence_by_page[page_number] = 0
                sequence_by_page[page_number] += 1
                sequence_id = sequence_by_page[page_number]
                
                # Determina tipo de transação
                transaction_type = 'compra'
                if 'pagamento' in description.lower():
                    transaction_type = 'pagamento'
                elif 'crédito' in description.lower() or 'credito' in description.lower():
                    transaction_type = 'credito'
                
                transaction = {
                    'data': parsed_date,
                    'descricao': description,
                    'valor': abs(value),
                    'tipo': transaction_type,
                    '_position': {
                        'page': page_number,
                        'y': line_y,
                        'x': value_x,
                        'round_y': round_y,
                        'round_x': round_x,
                        'sequence': sequence_id
                    }
                }
                
                transactions.append(transaction)
                
                # Debug
                if debug_states is not None:
                    debug_states.append({
                        'transaction': transaction,
                        'date': transaction_date,
                        'description_lines': pending_description_lines.copy(),
                        'value': value,
                        'start_y': pending_description_start_y if pending_description_start_y else line_y,
                        'last_y': line_y,
                        'closed_reason': 'value_detected',
                        'inherited_date': current_date_context != transaction_date if current_date_context else False,
                        'position_id': position_id
                    })
                
                if pending_debug is not None:
                    pending_debug.append({
                        'date': transaction_date,
                        'description_lines': pending_description_lines.copy(),
                        'value': value,
                        'line_y': line_y,
                        'line_x': value_x,
                        'position_id': position_id,
                        'inherited_date': True if current_date_context else False,
                        'accepted': True,
                        'rejection_reason': None
                    })
            else:
                # Duplicata detectada por posição
                if pending_debug is not None:
                    pending_debug.append({
                        'date': transaction_date,
                        'description_lines': pending_description_lines.copy(),
                        'value': value,
                        'line_y': line_y,
                        'line_x': value_x,
                        'position_id': position_id,
                        'inherited_date': False,
                        'accepted': False,
                        'rejection_reason': 'duplicate_position'
                    })
            
            # Limpa descrições pendentes após criar transação
            pending_description_lines = []
            pending_description_start_y = None
        
        # Se NÃO contém valor, acumula descrição se estiver na coluna de descrição
        else:
            # Verifica se linha tem conteúdo na coluna de descrição
            description_words = line.get('description', [])
            if description_words:
                desc_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in description_words)
                desc_text = desc_text.strip()
                
                # Ignora descrições muito curtas ou que são apenas números
                if desc_text and len(desc_text) >= 3 and not re.match(r'^\d+$', desc_text):
                    # Ignora descrições que são claramente cabeçalhos ou rodapés
                    skip_patterns = [
                        r'^data\s+movimentações',
                        r'^descrição',
                        r'^valor',
                        r'^total',
                        r'^limite',
                        r'^vencimento',
                        r'^emitido'
                    ]
                    should_skip = False
                    for pattern in skip_patterns:
                        if re.match(pattern, desc_text, re.IGNORECASE):
                            should_skip = True
                            break
                    
                    if not should_skip:
                        # Verifica proximidade com última descrição pendente
                        if pending_description_lines:
                            last_y = pending_description_start_y if pending_description_start_y else line_y
                            delta_y = abs(line_y - last_y)
                            
                            # Se está próxima (até 50px), acumula
                            if delta_y <= 50:
                                pending_description_lines.append(desc_text)
                                if pending_description_start_y is None:
                                    pending_description_start_y = line_y
                            else:
                                # Muito distante, limpa e começa nova
                                pending_description_lines = [desc_text]
                                pending_description_start_y = line_y
                        else:
                            # Primeira descrição pendente
                            pending_description_lines = [desc_text]
                            pending_description_start_y = line_y
            
            # Também verifica se há descrição em all_words (caso não tenha sido classificada corretamente)
            elif not description_words and all_words:
                # Tenta extrair descrição de all_words excluindo data e valor
                desc_parts = []
                for word in all_words:
                    if isinstance(word, dict):
                        text = word.get('text', '')
                        # Ignora datas e valores monetários
                        if not re.match(r'^\d{2}/\d{2}$', text) and not extract_value_tolerant(text):
                            # Verifica se está na região de descrição (não é data nem valor)
                            word_left = word.get('left', 0)
                            # Se não está na coluna de data nem de valor, pode ser descrição
                            if word_left > 400:  # Aproximadamente após coluna de data
                                desc_parts.append(text)
                
                if desc_parts:
                    desc_text = ' '.join(desc_parts).strip()
                    if desc_text and len(desc_text) >= 3 and not re.match(r'^\d+$', desc_text):
                        # Verifica proximidade
                        if pending_description_lines:
                            last_y = pending_description_start_y if pending_description_start_y else line_y
                            delta_y = abs(line_y - last_y)
                            if delta_y <= 50:
                                pending_description_lines.append(desc_text)
                                if pending_description_start_y is None:
                                    pending_description_start_y = line_y
                        else:
                            pending_description_lines = [desc_text]
                            pending_description_start_y = line_y
    
    return transactions


def extract_value_from_word(word_text):
    """Extrai valor monetário de uma palavra/texto"""
    # Remove R$ e normaliza
    text = re.sub(r'R\$\s*', '', word_text.strip())
    return normalize_amount(text)


def extract_date_blocks(text):
    """Extrai blocos de texto agrupados por data"""
    # Normaliza texto primeiro
    text = normalize_text(text)
    
    # Encontra todas as datas DD/MM (usando word boundary para evitar falsos positivos)
    date_pattern = r'\b(\d{2}/\d{2})\b'
    date_matches = list(re.finditer(date_pattern, text))
    
    if not date_matches:
        return []
    
    blocks = []
    
    # Cria blocos entre datas consecutivas
    for i, date_match in enumerate(date_matches):
        date_str = date_match.group(1)
        start_pos = date_match.start()
        
        # Pega texto até próxima data ou até 500 caracteres (para capturar descrições longas)
        if i + 1 < len(date_matches):
            end_pos = date_matches[i + 1].start()
        else:
            # Última data: pega até o final ou até 500 caracteres
            end_pos = min(start_pos + 500, len(text))
        
        block_text = text[start_pos:end_pos]
        
        # Ignora blocos muito pequenos
        if len(block_text.strip()) < 10:
            continue
        
        blocks.append({
            'date': date_str,
            'text': block_text,
            'start_pos': start_pos
        })
    
    return blocks


def extract_value_from_block(block_text):
    """Extrai valor monetário do bloco (último encontrado)"""
    # Procura todos os valores monetários no bloco
    value_patterns = [
        r'R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})',  # R$ 1.234,56
        r'R\$\s*(\d+,\d{2})',  # R$ 123,45
        r'R\$\s*(\d{1,3}(?:\.\d{3})*)',  # R$ 1.234 (sem centavos)
        r'R\$\s*(\d+)',  # R$ 123
        r'R\$\s*([\d\s\.,]{3,20})',  # Formato corrompido
    ]
    
    all_matches = []
    for pattern in value_patterns:
        matches = list(re.finditer(pattern, block_text))
        all_matches.extend(matches)
    
    if not all_matches:
        return None
    
    # Pega o último valor encontrado (mais provável de ser o valor da transação)
    last_match = max(all_matches, key=lambda m: m.start())
    amount_str = last_match.group(1)
    
    return normalize_amount(amount_str)


def extract_description_from_block(block_text, date_str, value_str=None):
    """Extrai descrição limpa do bloco removendo data e valor"""
    description = block_text
    
    # Remove a data do início
    description = re.sub(r'^\s*\d{2}/\d{2}\s+', '', description)
    
    # Remove o valor monetário (se fornecido)
    if value_str:
        # Tenta remover o valor em diferentes formatos
        value_patterns = [
            rf'R\$\s*{re.escape(str(value_str))}',
            rf'R\$\s*{re.escape(f"{value_str:.2f}")}',
            rf'R\$\s*[\d\s\.,]*{re.escape(str(int(value_str)))}',
        ]
        for pattern in value_patterns:
            description = re.sub(pattern, '', description, flags=re.IGNORECASE)
    
    # Remove valores monetários genéricos do final
    description = re.sub(r'\s*R\$\s*[\d\s\.,]+\s*$', '', description)
    
    # Preserva "Parcela X de Y" se existir
    parcela_match = re.search(r'Parcela\s+(\d+)\s+de\s+(\d+)', description, re.IGNORECASE)
    parcela_info = None
    if parcela_match:
        parcela_info = (parcela_match.group(1), parcela_match.group(2))
        # Remove da descrição temporariamente para limpar
        description = re.sub(r'\s*Parcela\s+\d+\s+de\s+\d+\s*', ' ', description, flags=re.IGNORECASE)
    
    # Limpa espaços e caracteres inválidos
    description = re.sub(r'[^A-Za-z0-9\s\*\-\.]', ' ', description)
    description = re.sub(r'\s+', ' ', description.strip())
    
    # Adiciona parcela de volta se existir
    if parcela_info:
        description += f' Parcela {parcela_info[0]} de {parcela_info[1]}'
    
    return description.strip()


def extract_transactions_from_text(text, invoice_year):
    """Extrai transações do texto usando agrupamento por blocos de data"""
    transactions = []
    
    # Extrai blocos agrupados por data
    blocks = extract_date_blocks(text)
    
    # Palavras-chave para ignorar blocos
    ignore_keywords = [
        'Total', 'Limite', 'Juros', 'CET', 'Parcelamento', 
        'Pagamento mínimo', 'Vencimento', 'Emitido', 'Consumos de',
        'USD', 'Valor em R', 'Crédito concedido'
    ]
    
    seen_hashes = set()
    
    for block in blocks:
        block_text = block['text']
        date_str = block['date']
        
        # Ignora blocos que contenham palavras-chave de ignorar
        block_lower = block_text.lower()
        if any(keyword.lower() in block_lower for keyword in ignore_keywords):
            continue
        
        # Extrai valor do bloco
        value = extract_value_from_block(block_text)
        if value is None or value <= 0 or value > 100000:
            continue
        
        # Extrai descrição
        description = extract_description_from_block(block_text, date_str, value)
        
        # Valida descrição
        if len(description) < 5:
            continue
        
        # Ignora descrições que são apenas números
        if re.match(r'^\d+$', description.strip()):
            continue
        
        # Parseia data
        parsed_date = parse_date(date_str, invoice_year)
        if not parsed_date:
            continue
        
        # Cria hash para deduplicação: (data + valor + descrição normalizada)
        desc_normalized = re.sub(r'\s+', ' ', description.lower().strip())
        transaction_hash = f"{parsed_date}|{value:.2f}|{desc_normalized[:50]}"
        
        if transaction_hash in seen_hashes:
            continue
        seen_hashes.add(transaction_hash)
        
        # Determina tipo de transação
        transaction_type = 'compra'
        if 'pagamento' in description.lower():
            transaction_type = 'pagamento'
        elif 'crédito' in description.lower():
            transaction_type = 'credito'
        
        transactions.append({
            'data': parsed_date,
            'descricao': description,
            'valor': abs(value),
            'tipo': transaction_type
        })
    
    return transactions


def extract_words_with_coordinates(image):
    """Extrai palavras com coordenadas usando pytesseract"""
    data = pytesseract.image_to_data(image, lang='por', output_type=pytesseract.Output.DICT)
    words = []
    
    for i, text in enumerate(data['text']):
        conf = int(data['conf'][i]) if data['conf'][i] != '-1' else 0
        if conf >= 40 and text.strip():
            words.append({
                'text': text.strip(),
                'left': int(data['left'][i]),
                'top': int(data['top'][i]),
                'width': int(data['width'][i]),
                'height': int(data['height'][i]),
                'conf': conf,
                'right': int(data['left'][i]) + int(data['width'][i]),
                'bottom': int(data['top'][i]) + int(data['height'][i])
            })
    
    return words


def reconstruct_lines(words):
    """Reconstrói linhas agrupando palavras por coordenada Y (diferença ≤ 8px)"""
    if not words:
        return []
    
    # Ordena palavras por top (Y)
    sorted_words = sorted(words, key=lambda w: w['top'])
    
    lines = []
    current_line = []
    current_top = None
    
    for word in sorted_words:
        if current_top is None:
            # Primeira palavra
            current_top = word['top']
            current_line = [word]
        elif abs(word['top'] - current_top) <= 8:
            # Mesma linha (diferença ≤ 8px)
            current_line.append(word)
        else:
            # Nova linha
            # Ordena linha atual por left (X)
            current_line.sort(key=lambda w: w['left'])
            lines.append(current_line)
            current_line = [word]
            current_top = word['top']
    
    # Adiciona última linha
    if current_line:
        current_line.sort(key=lambda w: w['left'])
        lines.append(current_line)
    
    return lines


def detect_columns(lines):
    """Detecta colunas baseado em coordenadas X com tolerância ±80px para valores"""
    # Detecta limites das colunas dinamicamente se possível
    # Primeiro, tenta detectar padrões conhecidos
    all_x_positions = []
    value_x_positions = []  # Posições X de valores monetários detectados
    
    for line in lines:
        if isinstance(line, list):
            for word in line:
                if isinstance(word, dict):
                    all_x_positions.append(word.get('left', 0))
                    # Detecta valores monetários para calcular coluna média
                    text = word.get('text', '')
                    if extract_value_tolerant(text) or 'R$' in text or 'R' in text:
                        value_x_positions.append(word.get('left', 0))
    
    if all_x_positions:
        min_x = min(all_x_positions)
        max_x = max(all_x_positions)
        # Ajusta limites baseado no tamanho real da página
        # Assumindo layout típico: DATA (0-15%), DESCRIÇÃO (15-85%), VALOR (85-100%)
        COLUMN_DATA_MAX = min_x + (max_x - min_x) * 0.15
        COLUMN_DESCRIPTION_MIN = min_x + (max_x - min_x) * 0.15
        COLUMN_DESCRIPTION_MAX = min_x + (max_x - min_x) * 0.85
        COLUMN_VALUE_MIN = min_x + (max_x - min_x) * 0.85
        
        # Calcula posição média da coluna de valores (se houver valores detectados)
        if value_x_positions:
            COLUMN_VALUE_MEAN = sum(value_x_positions) / len(value_x_positions)
            COLUMN_VALUE_TOLERANCE = 80  # ±80px de tolerância
            COLUMN_VALUE_MIN_TOLERANT = COLUMN_VALUE_MEAN - COLUMN_VALUE_TOLERANCE
            COLUMN_VALUE_MAX_TOLERANT = COLUMN_VALUE_MEAN + COLUMN_VALUE_TOLERANCE
        else:
            COLUMN_VALUE_MIN_TOLERANT = COLUMN_VALUE_MIN
            COLUMN_VALUE_MAX_TOLERANT = max_x + 100
    else:
        # Fallback para valores fixos (ajustados para DPI 300)
        COLUMN_DATA_MAX = 400
        COLUMN_DESCRIPTION_MIN = 400
        COLUMN_DESCRIPTION_MAX = 2400
        COLUMN_VALUE_MIN = 2400
        COLUMN_VALUE_MIN_TOLERANT = 2320  # 2400 - 80
        COLUMN_VALUE_MAX_TOLERANT = 2480  # 2400 + 80
    
    classified_lines = []
    
    for line in lines:
        # Verifica se line é uma lista de palavras
        if not isinstance(line, list):
            continue
            
        classified_line = {
            'data': [],
            'description': [],
            'value': [],
            'all_words': line,
            'top': min(w['top'] for w in line) if line else 0
        }
        
        for word in line:
            if not isinstance(word, dict):
                continue
            x = word.get('left', 0)
            if x < COLUMN_DATA_MAX:
                classified_line['data'].append(word)
            elif COLUMN_DESCRIPTION_MIN <= x <= COLUMN_DESCRIPTION_MAX:
                classified_line['description'].append(word)
            elif x >= COLUMN_VALUE_MIN_TOLERANT:  # Usa limite tolerante
                # Verifica se está dentro da tolerância da coluna de valores
                if COLUMN_VALUE_MIN_TOLERANT <= x <= COLUMN_VALUE_MAX_TOLERANT:
                    classified_line['value'].append(word)
                elif x > COLUMN_VALUE_MAX_TOLERANT:
                    # Muito à direita, ainda considera como valor
                    classified_line['value'].append(word)
                else:
                    # Entre descrição e valor, adiciona à descrição
                    classified_line['description'].append(word)
            else:
                # Palavra entre colunas, adiciona à descrição por padrão
                classified_line['description'].append(word)
        
        classified_lines.append(classified_line)
    
    return classified_lines


def extract_transactions_from_pdf(pdf_path, debug=False):
    """Extrai transações de um PDF usando OCR com coordenadas"""
    try:
        # Converte PDF em imagens
        images = convert_from_path(pdf_path, dpi=300)
        
        all_words = []
        all_lines = []
        
        # Extrai palavras com coordenadas de todas as páginas
        page_info = {}  # Mapeia line_idx -> page_number
        line_idx_offset = 0
        
        for i, image in enumerate(images):
            page_words = extract_words_with_coordinates(image)
            all_words.extend(page_words)
            
            # Reconstrói linhas
            page_lines = reconstruct_lines(page_words)
            
            # Mapeia índices de linhas para número da página
            for _ in page_lines:
                page_info[line_idx_offset] = i
                line_idx_offset += 1
            
            all_lines.extend(page_lines)
        
        # Detecta colunas
        classified_lines = detect_columns(all_lines)
        
        # Salva debug se solicitado
        if debug:
            save_debug_data(pdf_path, all_words, all_lines, classified_lines, [])
        
        # ORDEM CORRETA: OCR → tokens → fusão de valores → criação de transação → deduplicação
        # 1. Fusão de valores monetários fragmentados
        debug_fusion = [] if debug else None
        fused_values, consumed_lines = fuse_value_tokens(classified_lines, page_info, debug_fusion)
        
        # Salva debug de fusão
        if debug and debug_fusion:
            save_debug_value_fusion(pdf_path, debug_fusion, fused_values)
        
        # Extrai transações baseado no layout visual (usando valores fundidos)
        invoice_year = extract_invoice_year_from_words(all_words)
        debug_states = [] if debug else None
        pending_debug = [] if debug else None
        
        transactions = extract_transactions_from_lines(classified_lines, invoice_year, debug_states, pending_debug, page_info, fused_values, consumed_lines)
        
        # Conta valores detectados para debug
        all_detected_values = []
        for line_idx, line in enumerate(classified_lines):
            if isinstance(line, dict):
                value_col_words = line.get('value', [])
                if value_col_words:
                    value_col_text = ' '.join(w.get('text', '') if isinstance(w, dict) else str(w) for w in value_col_words)
                    value = extract_value_tolerant(value_col_text)
                    if value and value > 0 and value <= 100000:
                        all_detected_values.append({
                            'value': value,
                            'line_y': line.get('top', 0),
                            'line_idx': line_idx,
                            'value_words': [w.get('text', '') if isinstance(w, dict) else str(w) for w in value_col_words]
                        })
        
        # Salva debug de todos os valores detectados
        if debug:
            save_debug_all_detected_values(pdf_path, all_detected_values, transactions)
        
        # Remove campos de debug interno antes de retornar (mas mantém cópia para debug)
        transactions_with_position = []
        for t in transactions:
            t_copy = t.copy()
            if '_position' in t_copy:
                transactions_with_position.append(t_copy)
            if '_position' in t:
                del t['_position']
        
        # Ordena por data (transações sem data vão para o final)
        transactions.sort(key=lambda x: (x['data'] is None, x['data'] or ''))
        
        # Salva transações e estados para debug
        if debug:
            save_debug_transactions(pdf_path, transactions)
            if debug_states:
                save_debug_transaction_states(pdf_path, debug_states)
            if pending_debug:
                save_debug_pending_descriptions(pdf_path, pending_debug)
            if transactions_with_position:
                save_debug_deduplication(pdf_path, all_detected_values, transactions_with_position, pending_debug)
                save_debug_filtered_transactions(pdf_path, transactions, pending_debug)
        
        # Meta: valores detectados vs transações criadas
        values_detected = len(all_detected_values)
        transactions_created = len(transactions)
        
        return {
            'transactions': transactions,
            'total_encontrado': len(transactions),
            'valores_detectados': values_detected,
            'transacoes_criadas': transactions_created,
            'periodo': {
                'inicio': transactions[0]['data'] if transactions and transactions[0]['data'] else None,
                'fim': transactions[-1]['data'] if transactions and transactions[-1]['data'] else None
            },
            'method': 'coordinates'
        }
        
    except Exception as e:
        # Fallback para método antigo se OCR com coordenadas falhar
        error_msg = f"OCR com coordenadas falhou: {str(e)}"
        if debug:
            print(f"Warning: {error_msg}", file=sys.stderr)
        return extract_transactions_from_pdf_fallback(pdf_path, debug, error_msg)


def save_debug_data(pdf_path, words, lines, classified_lines, transactions):
    """Salva dados de debug: palavras, linhas e transações"""
    base_path = Path(pdf_path).parent
    
    # Salva palavras com coordenadas
    words_data = [
        {
            'text': w.get('text', '') if isinstance(w, dict) else str(w),
            'left': w.get('left', 0) if isinstance(w, dict) else 0,
            'top': w.get('top', 0) if isinstance(w, dict) else 0,
            'width': w.get('width', 0) if isinstance(w, dict) else 0,
            'height': w.get('height', 0) if isinstance(w, dict) else 0,
            'conf': w.get('conf', 0) if isinstance(w, dict) else 0
        }
        for w in words
    ]
    with open(base_path / 'debug_words.json', 'w', encoding='utf-8') as f:
        json.dump(words_data, f, ensure_ascii=False, indent=2)
    
    # Salva linhas reconstruídas
    lines_data = []
    for line in (classified_lines if classified_lines and len(classified_lines) > 0 and isinstance(classified_lines[0], dict) else lines):
        if isinstance(line, dict):
            lines_data.append({
                'words': [w.get('text', '') if isinstance(w, dict) else str(w) for w in line.get('all_words', [])],
                'top': line.get('top', 0),
                'data': [w.get('text', '') if isinstance(w, dict) else str(w) for w in line.get('data', [])],
                'description': [w.get('text', '') if isinstance(w, dict) else str(w) for w in line.get('description', [])],
                'value': [w.get('text', '') if isinstance(w, dict) else str(w) for w in line.get('value', [])]
            })
        elif isinstance(line, list):
            lines_data.append({
                'words': [w.get('text', '') if isinstance(w, dict) else str(w) for w in line],
                'top': min(w.get('top', 0) if isinstance(w, dict) else 0 for w in line) if line else 0,
                'data': [],
                'description': [],
                'value': []
            })
    
    with open(base_path / 'debug_lines.json', 'w', encoding='utf-8') as f:
        json.dump(lines_data, f, ensure_ascii=False, indent=2)


def save_debug_transactions(pdf_path, transactions):
    """Salva transações antes da deduplicação para debug"""
    base_path = Path(pdf_path).parent
    with open(base_path / 'debug_transactions.json', 'w', encoding='utf-8') as f:
        json.dump(transactions, f, ensure_ascii=False, indent=2)


def save_debug_transaction_states(pdf_path, debug_states):
    """Salva estados de transações em construção para debug"""
    base_path = Path(pdf_path).parent
    with open(base_path / 'debug_transaction_states.json', 'w', encoding='utf-8') as f:
        json.dump(debug_states, f, ensure_ascii=False, indent=2)


def save_debug_pending_descriptions(pdf_path, pending_debug):
    """Salva pending descriptions para debug"""
    base_path = Path(pdf_path).parent
    with open(base_path / 'debug_pending_descriptions.json', 'w', encoding='utf-8') as f:
        json.dump(pending_debug, f, ensure_ascii=False, indent=2)


def save_debug_value_fusion(pdf_path, debug_fusion, fused_values):
    """Salva informações sobre fusão de valores monetários"""
    base_path = Path(pdf_path).parent
    
    with open(base_path / 'debug_value_fusion.json', 'w', encoding='utf-8') as f:
        json.dump({
            'total_valores_fundidos': len(fused_values),
            'fusoes': debug_fusion,
            'valores_finais': [
                {
                    'value': fv['value'],
                    'y': fv['y'],
                    'x': fv['x'],
                    'line_idx': fv['line_idx'],
                    'tokens': fv['tokens']
                }
                for fv in fused_values
            ]
        }, f, ensure_ascii=False, indent=2)


def save_debug_deduplication(pdf_path, all_detected_values, transactions, pending_debug):
    """Salva informações sobre deduplicação baseada em posição"""
    base_path = Path(pdf_path).parent
    
    # Analisa transações mantidas vs descartadas
    kept_transactions = []
    discarded_transactions = []
    
    # Mapeia valores detectados para transações criadas
    for detected in all_detected_values:
        round_y = round(detected['line_y'] / 10)
        found_match = False
        
        for t in transactions:
            pos = t.get('_position', {})
            t_round_y = pos.get('round_y', 0)
            if abs(t_round_y - round_y) <= 1:
                found_match = True
                kept_transactions.append({
                    'detected_value': detected,
                    'transaction': t,
                    'position': pos
                })
                break
        
        if not found_match:
            discarded_transactions.append({
                'detected_value': detected,
                'reason': 'no_matching_position'
            })
    
    # Analisa pending_debug para encontrar duplicatas por posição
    for entry in pending_debug or []:
        if not entry.get('accepted', False):
            if entry.get('rejection_reason') == 'duplicate_position':
                discarded_transactions.append({
                    'detected_value': {
                        'value': entry.get('value'),
                        'line_y': entry.get('line_y'),
                        'line_x': entry.get('line_x')
                    },
                    'position_id': entry.get('position_id'),
                    'reason': 'duplicate_position'
                })
    
    with open(base_path / 'debug_deduplication.json', 'w', encoding='utf-8') as f:
        json.dump({
            'total_valores_detectados': len(all_detected_values),
            'total_transacoes_criadas': len(transactions),
            'transacoes_mantidas': len(kept_transactions),
            'transacoes_descartadas': len(discarded_transactions),
            'diferenca': len(all_detected_values) - len(transactions),
            'mantidas': kept_transactions,
            'descartadas': discarded_transactions
        }, f, ensure_ascii=False, indent=2)


def save_debug_all_detected_values(pdf_path, all_detected_values, transactions):
    """Salva todos os valores detectados e compara com transações criadas"""
    base_path = Path(pdf_path).parent
    
    # Cria mapa de valores para transações criadas
    created_values = {}
    for t in transactions:
        key = f"{t['valor']:.2f}"
        if key not in created_values:
            created_values[key] = []
        created_values[key].append(t)
    
    # Analisa cada valor detectado
    debug_data = []
    for detected in all_detected_values:
        value_key = f"{detected['value']:.2f}"
        was_created = value_key in created_values
        
        debug_data.append({
            'value': detected['value'],
            'line_y': detected['line_y'],
            'line_idx': detected['line_idx'],
            'value_words': detected['value_words'],
            'accepted': was_created,
            'rejection_reason': None if was_created else 'unknown',
            'transactions_created': created_values.get(value_key, [])
        })
    
    with open(base_path / 'debug_all_detected_values.json', 'w', encoding='utf-8') as f:
        json.dump({
            'total_valores_detectados': len(all_detected_values),
            'total_transacoes_criadas': len(transactions),
            'valores': debug_data
        }, f, ensure_ascii=False, indent=2)


def save_debug_filtered_transactions(pdf_path, transactions, pending_debug):
    """Salva informações sobre transações filtradas (aceitas vs descartadas)"""
    base_path = Path(pdf_path).parent
    
    accepted_transactions = []
    discarded_transactions = []
    
    # Transações aceitas são as que foram criadas
    for t in transactions:
        accepted_transactions.append({
            'transaction': t,
            'reason': 'accepted'
        })
    
    # Transações descartadas estão em pending_debug com accepted=False
    for entry in pending_debug or []:
        if not entry.get('accepted', False):
            discarded_transactions.append({
                'date': entry.get('date'),
                'description': entry.get('description', ''),
                'value': entry.get('value'),
                'line_y': entry.get('line_y'),
                'rejection_reason': entry.get('rejection_reason', 'unknown')
            })
    
    with open(base_path / 'debug_filtered_transactions.json', 'w', encoding='utf-8') as f:
        json.dump({
            'total_aceitas': len(accepted_transactions),
            'total_descartadas': len(discarded_transactions),
            'aceitas': accepted_transactions,
            'descartadas': discarded_transactions
        }, f, ensure_ascii=False, indent=2)


def extract_transactions_from_pdf_fallback(pdf_path, debug=False, error_msg=None):
    """Fallback: Extrai transações usando método antigo baseado em texto"""
    try:
        # Converte PDF em imagens
        images = convert_from_path(pdf_path, dpi=300)
        
        # Extrai texto de todas as páginas
        full_text = ""
        for i, image in enumerate(images):
            # Aplica OCR com idioma português
            page_text = pytesseract.image_to_string(image, lang='por')
            full_text += page_text + "\n"
        
        # Salva texto OCR bruto para debug
        if debug:
            debug_file = Path(pdf_path).parent / 'debug_ocr.txt'
            with open(debug_file, 'w', encoding='utf-8') as f:
                f.write(full_text)
        
        # Extrai ano da fatura
        invoice_year = extract_invoice_year(full_text)
        
        # Extrai blocos de data para debug
        blocks = extract_date_blocks(full_text)
        
        # Salva blocos detectados para debug
        if debug:
            debug_blocks_file = Path(pdf_path).parent / 'debug_blocos.json'
            blocks_data = [
                {
                    'date': b['date'],
                    'text': b['text'][:200],  # Limita tamanho para JSON
                    'start_pos': b['start_pos']
                }
                for b in blocks
            ]
            with open(debug_blocks_file, 'w', encoding='utf-8') as f:
                json.dump(blocks_data, f, ensure_ascii=False, indent=2)
        
        # Processa todo o texto usando agrupamento por blocos
        transactions = extract_transactions_from_text(full_text, invoice_year)
        
        # Ordena por data
        transactions.sort(key=lambda x: x['data'])
        
        return {
            'transactions': transactions,
            'total_encontrado': len(transactions),
            'periodo': {
                'inicio': transactions[0]['data'] if transactions else None,
                'fim': transactions[-1]['data'] if transactions else None
            }
        }
        
    except Exception as e:
        return {
            'error': str(e),
            'transactions': [],
            'total_encontrado': 0
        }


def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'error': 'Caminho do PDF não fornecido',
            'transactions': []
        }), file=sys.stderr)
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    
    if not Path(pdf_path).exists():
        print(json.dumps({
            'error': f'Arquivo não encontrado: {pdf_path}',
            'transactions': []
        }), file=sys.stderr)
        sys.exit(1)
    
    debug = '--debug' in sys.argv or '-d' in sys.argv
    result = extract_transactions_from_pdf(pdf_path, debug=debug)
    
    # Validação: compara com pdftotext se disponível
    try:
        import subprocess
        pdftotext_result = subprocess.run(
            ['pdftotext', '-layout', pdf_path, '-'],
            capture_output=True,
            text=True,
            timeout=30
        )
        if pdftotext_result.returncode == 0:
            pdftotext_text = pdftotext_result.stdout
            # Conta datas no texto do pdftotext
            pdftotext_dates = len(re.findall(r'\b\d{2}/\d{2}\b', pdftotext_text))
            ocr_count = result.get('total_encontrado', 0)
            
            if ocr_count < pdftotext_dates:
                result['warning'] = f'OCR extraiu {ocr_count} transações, mas pdftotext encontrou {pdftotext_dates} datas possíveis'
    except Exception:
        pass  # pdftotext não disponível ou erro, ignora
    
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()

