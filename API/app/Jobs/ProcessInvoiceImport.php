<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\InvoiceParserService;
use App\Services\CategoryMatcherService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessInvoiceImport implements ShouldQueue
{
    use Queueable;

    /**
     * Contagem de ocorrências por fingerprint durante a importação atual.
     * Permite linhas idênticas no CSV (ex.: cobranças repetidas no extrato).
     *
     * @var array<string, int>
     */
    private array $importFingerprintCounts = [];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public int $userId,
        public string $bankName
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        InvoiceParserService $parserService,
        CategoryMatcherService $matcherService
    ): void {
        try {
            Log::info("Iniciando processamento de importação: {$this->filePath}");
            Log::info("User ID: {$this->userId}");
            Log::info("Bank Name: {$this->bankName}");
            
            // Verifica se o arquivo existe antes de processar
            $fullPath1 = Storage::path($this->filePath);
            $fullPath2 = storage_path('app/' . $this->filePath);
            $fullPath3 = storage_path('app/public/' . $this->filePath);
            
            Log::info("Tentando caminho 1: {$fullPath1}, Existe: " . (file_exists($fullPath1) ? 'sim' : 'não'));
            Log::info("Tentando caminho 2: {$fullPath2}, Existe: " . (file_exists($fullPath2) ? 'sim' : 'não'));
            Log::info("Tentando caminho 3: {$fullPath3}, Existe: " . (file_exists($fullPath3) ? 'sim' : 'não'));
            
            $fullPath = null;
            if (file_exists($fullPath1)) {
                $fullPath = $fullPath1;
            } elseif (file_exists($fullPath2)) {
                $fullPath = $fullPath2;
            } elseif (file_exists($fullPath3)) {
                $fullPath = $fullPath3;
            }
            
            if (!$fullPath || !file_exists($fullPath)) {
                Log::error("Arquivo não encontrado em nenhum caminho tentado", [
                    'file_path' => $this->filePath,
                    'paths_tried' => [$fullPath1, $fullPath2, $fullPath3],
                ]);
                throw new \RuntimeException("Arquivo não encontrado: {$this->filePath}");
            }
            
            Log::info("Arquivo encontrado em: {$fullPath}");

            // Detecta tipo de arquivo
            try {
                $fileType = $parserService->detectFileType($this->filePath);
                Log::info("Tipo de arquivo detectado: {$fileType}");
            } catch (\Exception $e) {
                Log::error("Erro ao detectar tipo de arquivo: " . $e->getMessage());
                throw $e;
            }
            
            // Extrai transações do arquivo
            try {
                Log::info("Iniciando extração de transações...");
                $transactions = match ($fileType) {
                    'csv' => $parserService->parseCsv($this->filePath),
                    'pdf' => $parserService->parsePdf($this->filePath),
                    default => throw new \RuntimeException("Tipo de arquivo não suportado: {$fileType}"),
                };
                Log::info("Extraídas " . count($transactions) . " transações do arquivo");
            } catch (\Exception $e) {
                Log::error("Erro ao extrair transações: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            $processed = 0;
            $categorized = 0;
            $pending = 0;

            // Verifica se o usuário possui categorias com keywords
            $hasKeywords = $matcherService->hasCategoriesWithKeywords($this->userId);
            
            if (!$hasKeywords) {
                Log::info("Nenhuma categoria com keywords encontrada. Todas as transações serão criadas como pendentes.");
            }

            $this->importFingerprintCounts = [];

            // Processa cada transação
            foreach ($transactions as $transactionData) {
                try {
                    $description = trim($transactionData['description']);
                    $installmentMetadata = $parserService->extractInstallmentMetadata($description);
                    $isInstallment = $installmentMetadata !== null;
                    $transactionType = in_array($transactionData['type'] ?? null, ['income', 'expense'], true)
                        ? $transactionData['type']
                        : 'expense';

                    if ($isInstallment) {
                        $description = $installmentMetadata['normalized_description'];
                    }

                    $category = null;
                    
                    // Só tenta fazer match se houver categorias com keywords
                    if ($hasKeywords && $transactionType === 'expense') {
                        $descriptionForMatch = $installmentMetadata['base_description'] ?? $description;

                        // Tenta encontrar categoria por palavras-chave
                        $category = $matcherService->matchCategory(
                            $descriptionForMatch,
                            $this->userId
                        );
                    }

                    // Cria a transação atual (evita duplicidade em reimportação)
                    $basePayload = [
                        'user_id' => $this->userId,
                        'category_id' => $category?->id ?? null,
                        'type' => $transactionType,
                        'amount' => $transactionData['amount'],
                        'description' => $description,
                        'date' => $transactionData['date'],
                        'status' => $category ? 'categorized' : 'pending',
                        'bank_name' => $this->bankName,
                        'is_installment' => $isInstallment,
                    ];

                    $transaction = $this->createTransactionIfNotExists($basePayload);
                    
                    // Log para debug (apenas primeira transação)
                    if ($transaction && $processed === 0) {
                        Log::info("Primeira transação criada com bank_name", [
                            'transaction_id' => $transaction->id,
                            'bank_name' => $transaction->bank_name,
                            'expected_bank_name' => $this->bankName,
                        ]);
                    }

                    if ($transaction) {
                        $processed++;
                        if ($category) {
                            $categorized++;
                        } else {
                            $pending++;
                        }
                    }

                    // Gera parcelas futuras quando a transação importada já é parcelada
                    if ($isInstallment) {
                        $currentInstallment = $installmentMetadata['current_installment'];
                        $totalInstallments = $installmentMetadata['total_installments'];
                        $baseDescription = $installmentMetadata['base_description'];

                        if ($currentInstallment < $totalInstallments) {
                            $baseDate = Carbon::parse($transactionData['date']);

                            for ($installmentNumber = $currentInstallment + 1; $installmentNumber <= $totalInstallments; $installmentNumber++) {
                                $monthsToAdd = $installmentNumber - $currentInstallment;
                                $futureDate = $baseDate->copy()->addMonthsNoOverflow($monthsToAdd)->toDateString();
                                $futureDescription = $parserService->buildInstallmentDescription(
                                    $baseDescription,
                                    $installmentNumber,
                                    $totalInstallments
                                );

                                $futurePayload = [
                                    'user_id' => $this->userId,
                                    'category_id' => $category?->id ?? null,
                                    'type' => $transactionType,
                                    'amount' => $transactionData['amount'],
                                    'description' => $futureDescription,
                                    'date' => $futureDate,
                                    'status' => $category ? 'categorized' : 'pending',
                                    'bank_name' => $this->bankName,
                                    'is_installment' => true,
                                ];

                                $futureTransaction = $this->createTransactionIfNotExists($futurePayload);
                                if ($futureTransaction) {
                                    $processed++;
                                    if ($category) {
                                        $categorized++;
                                    } else {
                                        $pending++;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Erro ao processar transação: " . $e->getMessage(), [
                        'transaction' => $transactionData,
                    ]);
                    // Continua processando outras transações
                }
            }

            Log::info("Processamento concluído: {$processed} processadas, {$categorized} categorizadas, {$pending} pendentes");

            // Remove arquivo temporário após processamento
            $fileToDelete = storage_path('app/' . $this->filePath);
            if (file_exists($fileToDelete)) {
                unlink($fileToDelete);
                Log::info("Arquivo temporário removido: {$fileToDelete}");
            } elseif (Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
                Log::info("Arquivo temporário removido via Storage: {$this->filePath}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar importação: " . $e->getMessage(), [
                'file_path' => $this->filePath,
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw para que o Laravel possa registrar como falha
            throw $e;
        }
    }

    private function createTransactionIfNotExists(array $payload): ?Transaction
    {
        $fingerprint = $this->buildTransactionFingerprint($payload);
        $this->importFingerprintCounts[$fingerprint] = ($this->importFingerprintCounts[$fingerprint] ?? 0) + 1;
        $requiredCount = $this->importFingerprintCounts[$fingerprint];

        $existingCount = $this->countExistingTransactions($payload);

        if ($existingCount >= $requiredCount) {
            return null;
        }

        return Transaction::create($payload);
    }

    private function buildTransactionFingerprint(array $payload): string
    {
        return implode('|', [
            (string) $payload['user_id'],
            (string) $payload['type'],
            number_format((float) $payload['amount'], 2, '.', ''),
            (string) $payload['description'],
            (string) $payload['date'],
            (string) $payload['bank_name'],
        ]);
    }

    private function countExistingTransactions(array $payload): int
    {
        return Transaction::query()
            ->where('user_id', $payload['user_id'])
            ->where('type', $payload['type'])
            ->where('amount', number_format((float) $payload['amount'], 2, '.', ''))
            ->where('description', $payload['description'])
            ->where('date', $payload['date'])
            ->where('bank_name', $payload['bank_name'])
            ->count();
    }
}
