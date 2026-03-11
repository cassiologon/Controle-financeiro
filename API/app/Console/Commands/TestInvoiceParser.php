<?php

namespace App\Console\Commands;

use App\Services\InvoiceParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestInvoiceParser extends Command
{
    protected $signature = 'test:invoice-parser {file}';
    protected $description = 'Testa o parser de faturas com um arquivo CSV ou PDF';

    public function handle(InvoiceParserService $parserService)
    {
        $file = $this->argument('file');
        
        $this->info("Testando parser com arquivo: {$file}");
        
        // Tenta encontrar o arquivo
        if (!file_exists($file)) {
            $this->error("Arquivo não encontrado: {$file}");
            return 1;
        }
        
        // Copia para o storage temporariamente
        $tempPath = 'test-invoice-' . time() . '.' . pathinfo($file, PATHINFO_EXTENSION);
        Storage::put($tempPath, file_get_contents($file));
        
        try {
            // Detecta o tipo de arquivo
            $fileType = $parserService->detectFileType($tempPath);
            $this->info("Tipo de arquivo detectado: {$fileType}");
            
            // Chama o método correto baseado no tipo
            $transactions = match ($fileType) {
                'csv' => $parserService->parseCsv($tempPath),
                'pdf' => $parserService->parsePdf($tempPath),
                default => throw new \RuntimeException("Tipo de arquivo não suportado: {$fileType}"),
            };
            
            $this->info("Transações extraídas: " . count($transactions));
            
            if (count($transactions) > 0) {
                $this->table(
                    ['Data', 'Descrição', 'Valor'],
                    array_map(function($t) {
                        return [
                            $t['date'],
                            substr($t['description'], 0, 40),
                            'R$ ' . number_format($t['amount'], 2, ',', '.'),
                        ];
                    }, array_slice($transactions, 0, 10))
                );
            } else {
                $this->warn("Nenhuma transação foi extraída!");
            }
        } catch (\Exception $e) {
            $this->error("Erro: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        } finally {
            Storage::delete($tempPath);
        }
        
        return 0;
    }
}
