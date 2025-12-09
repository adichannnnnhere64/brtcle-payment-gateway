<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_external')->default(false);
            $table->integer('priority')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('gateway_name');
            $table->decimal('amount', 12, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->json('payer_info')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('webhook_received')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['gateway_transaction_id', 'gateway_name']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_gateways');
    }
};
