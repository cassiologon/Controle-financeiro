<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('bank_name');
            $table->unsignedTinyInteger('closing_day');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'bank_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_configs');
    }
};
