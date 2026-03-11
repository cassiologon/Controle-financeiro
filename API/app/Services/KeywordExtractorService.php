<?php

namespace App\Services;

class KeywordExtractorService
{
    /**
     * Extrai palavras significativas da descrição da transação
     * Filtra palavras com mais de 3 caracteres e normaliza
     * 
     * @param string $description Descrição da transação
     * @return array Array de palavras significativas normalizadas
     */
    public function extractSignificantWords(string $description): array
    {
        if (empty(trim($description))) {
            return [];
        }

        // Remove caracteres especiais e números, mantendo apenas letras e espaços
        $cleaned = preg_replace('/[^a-zA-ZÀ-ÿ\s]/u', ' ', $description);
        
        // Divide em palavras
        $words = preg_split('/\s+/', $cleaned);
        
        // Filtra e normaliza palavras
        $significantWords = [];
        foreach ($words as $word) {
            $word = trim($word);
            
            // Filtra palavras com mais de 3 caracteres
            if (mb_strlen($word) > 3) {
                // Normaliza para lowercase
                $normalized = mb_strtolower($word, 'UTF-8');
                
                // Adiciona apenas se não estiver vazia e não for duplicata
                if (!empty($normalized) && !in_array($normalized, $significantWords, true)) {
                    $significantWords[] = $normalized;
                }
            }
        }
        
        return $significantWords;
    }
}

