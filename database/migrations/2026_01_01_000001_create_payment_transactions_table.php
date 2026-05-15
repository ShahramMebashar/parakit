<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('gateway', 32)->index();
            $t->string('reference')->index();
            $t->string('gateway_transaction_id')->nullable()->index();
            $t->string('status', 32)->index();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3);
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('correlation_id')->index();
            $t->json('metadata')->nullable();
            $t->json('last_raw_response')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['gateway', 'gateway_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
