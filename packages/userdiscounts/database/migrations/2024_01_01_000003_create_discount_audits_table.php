<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discount_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('discount_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_discount_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action'); // 'assigned', 'revoked', 'applied', 'rejected'
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('final_amount', 10, 2)->nullable();
            $table->text('metadata')->nullable(); // JSON for additional context
            $table->string('transaction_id')->nullable(); // For idempotency
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['discount_id', 'created_at']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_audits');
    }
};

