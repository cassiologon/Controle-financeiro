<?php

namespace App\Services;

use App\Models\Category;

class CategoryMatcherService
{
    /**
     * Verifica se o usuário possui pelo menos uma categoria de despesa com keywords não vazias
     */
    public function hasCategoriesWithKeywords(int $userId): bool
    {
        $categories = Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereNotNull('keywords')
            ->get();
        
        foreach ($categories as $category) {
            if ($category->keywords && is_array($category->keywords) && count($category->keywords) > 0) {
                // Verifica se há pelo menos uma keyword não vazia
                foreach ($category->keywords as $keyword) {
                    if (!empty(trim($keyword))) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Tenta encontrar uma categoria para a descrição da transação
     * usando palavras-chave das categorias do usuário
     */
    public function matchCategory(string $description, int $userId): ?Category
    {
        // Busca todas as categorias de despesa do usuário
        $categories = Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereNotNull('keywords')
            ->get();
        
        $descriptionLower = strtolower(trim($description));
        
        // Divide a descrição em palavras
        $words = preg_split('/\s+/', $descriptionLower);
        
        // Para cada categoria, verifica se alguma palavra-chave está na descrição
        foreach ($categories as $category) {
            if (!$category->keywords || !is_array($category->keywords)) {
                continue;
            }
            
            foreach ($category->keywords as $keyword) {
                $keywordLower = strtolower(trim($keyword));
                
                // Verifica se a palavra-chave está na descrição
                // Pode ser uma palavra completa ou parte de uma palavra
                if (strlen($keywordLower) > 2) {
                    // Busca palavra completa primeiro
                    if (in_array($keywordLower, $words)) {
                        return $category;
                    }
                    
                    // Busca como substring (ex: "burguer" em "brutus burguer")
                    if (strpos($descriptionLower, $keywordLower) !== false) {
                        return $category;
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Verifica se uma descrição se encaixa nas keywords de uma categoria específica
     */
    public function matchesCategory(string $description, Category $category): bool
    {
        if (!$category->keywords || !is_array($category->keywords) || count($category->keywords) === 0) {
            return false;
        }

        $descriptionLower = strtolower(trim($description));
        $words = preg_split('/\s+/', $descriptionLower);

        foreach ($category->keywords as $keyword) {
            $keywordLower = strtolower(trim($keyword));

            // Verifica se a palavra-chave está na descrição
            if (strlen($keywordLower) > 2) {
                // Busca palavra completa primeiro
                if (in_array($keywordLower, $words)) {
                    return true;
                }

                // Busca como substring (ex: "burguer" em "brutus burguer")
                if (strpos($descriptionLower, $keywordLower) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

