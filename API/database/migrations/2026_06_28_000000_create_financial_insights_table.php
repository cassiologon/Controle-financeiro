<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('data_hash', 64);
            $table->text('summary');
            $table->decimal('total_potential_savings', 15, 2)->default(0);
            $table->json('insights');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_insights');
    }
};
