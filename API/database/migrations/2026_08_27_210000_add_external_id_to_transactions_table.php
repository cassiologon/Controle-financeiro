<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // FITID cru do OFX. Nao e unico: o Nubank reaproveita o mesmo id para
            // transacoes relacionadas (o IOF herda o id da compra internacional).
            $table->string('external_id')->nullable()->after('bank_name');

            // Chave deterministica derivada do registro do OFX, usada na deduplicacao.
            $table->string('external_ref', 40)->nullable()->after('external_id');

            $table->index(['user_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'external_ref']);
            $table->dropColumn(['external_id', 'external_ref']);
        });
    }
};
