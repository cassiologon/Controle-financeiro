#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script de teste para extração de transações de PDF
"""

import sys
import json
from pathlib import Path

# Adiciona o diretório scripts ao path
sys.path.insert(0, str(Path(__file__).parent))

from extract_pdf_transactions import extract_transactions_from_pdf

def main():
    if len(sys.argv) < 2:
        print("Uso: python3 test_pdf_extraction.py <caminho_do_pdf>")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    
    if not Path(pdf_path).exists():
        print(f"Erro: Arquivo não encontrado: {pdf_path}")
        sys.exit(1)
    
    print(f"Testando extração de transações de: {pdf_path}")
    print("=" * 60)
    
    result = extract_transactions_from_pdf(pdf_path)
    
    if 'error' in result:
        print(f"Erro: {result['error']}")
        sys.exit(1)
    
    transactions = result.get('transactions', [])
    total = result.get('total_encontrado', 0)
    
    print(f"\nTotal de transações encontradas: {total}")
    print("=" * 60)
    
    if transactions:
        print("\nTransações extraídas:\n")
        for i, txn in enumerate(transactions, 1):
            print(f"{i:2d}. {txn['data']} | {txn['descricao'][:50]:50s} | R$ {txn['valor']:>10.2f}")
        
        print("\n" + "=" * 60)
        print(f"Resumo:")
        print(f"  - Total de transações: {len(transactions)}")
        print(f"  - Valor total: R$ {sum(t['valor'] for t in transactions):.2f}")
        print(f"  - Período: {transactions[0]['data']} a {transactions[-1]['data']}")
    else:
        print("\nNenhuma transação foi extraída!")
    
    print("\nJSON completo:")
    print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == '__main__':
    main()

