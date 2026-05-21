<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('gateway', 32)->index();
            $t->string('idempotency_key', 64);
            $t->string('gateway_transaction_id')->index();
            $t->unsignedBigInteger('amount');
            $t->string('status', 32)->index();
            $t->string('refund_id')->nullable();
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->json('response')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['gateway', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
