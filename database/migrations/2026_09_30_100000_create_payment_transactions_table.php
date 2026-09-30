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
        // One row per Paystack transaction attempt. plan_id/subscription_id
        // are nullable + null-on-delete so this record survives even if
        // the plan or the subscription request it was for is later
        // removed — amount is captured here precisely so a later plan
        // price change never rewrites what was actually charged.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('reference')->unique();
            // Smallest currency unit (kobo for NGN), exactly as Paystack expects.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('NGN');
            // pending | successful | failed | abandoned
            $table->string('status')->default('pending');
            $table->string('paystack_transaction_id')->nullable();
            $table->string('channel')->nullable();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            // A short, safe label (e.g. "amount_mismatch") — never a raw
            // Paystack payload or anything secret.
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
