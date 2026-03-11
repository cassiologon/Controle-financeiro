<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'icon',
        'color',
        'keywords',
    ];

    protected $casts = [
        'keywords' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function hasKeyword(string $keyword): bool
    {
        if (!$this->keywords || !is_array($this->keywords)) {
            return false;
        }

        $keywordLower = strtolower($keyword);
        foreach ($this->keywords as $catKeyword) {
            if (strtolower($catKeyword) === $keywordLower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adiciona novas keywords à categoria, removendo duplicatas
     * Normaliza todas as keywords (lowercase, trim) antes de adicionar
     * 
     * @param array $newKeywords Array de novas keywords para adicionar
     * @return void
     */
    public function addKeywords(array $newKeywords): void
    {
        if (empty($newKeywords)) {
            return;
        }

        // Obtém keywords existentes ou inicializa array vazio
        $existingKeywords = $this->keywords ?? [];
        if (!is_array($existingKeywords)) {
            $existingKeywords = [];
        }

        // Normaliza keywords existentes (lowercase, trim)
        $normalizedExisting = array_map(function ($keyword) {
            return mb_strtolower(trim($keyword), 'UTF-8');
        }, $existingKeywords);

        // Normaliza novas keywords, remove vazias e filtra duplicatas
        $normalizedNew = [];
        foreach ($newKeywords as $keyword) {
            $normalized = mb_strtolower(trim($keyword), 'UTF-8');
            
            // Verifica se não está vazia e se já não existe nas keywords existentes
            if (!empty($normalized) && !in_array($normalized, $normalizedExisting, true)) {
                $normalizedNew[] = $normalized;
            }
        }

        // Se não há novas keywords para adicionar, não precisa atualizar
        if (empty($normalizedNew)) {
            return;
        }

        // Mescla keywords (já filtradas para não ter duplicatas)
        $mergedKeywords = array_merge($normalizedExisting, $normalizedNew);

        // Remove valores vazios e reindexa array
        $mergedKeywords = array_values(array_filter($mergedKeywords, function ($keyword) {
            return !empty(trim($keyword));
        }));

        // Atualiza e salva
        $this->keywords = $mergedKeywords;
        $this->save();
    }
}

