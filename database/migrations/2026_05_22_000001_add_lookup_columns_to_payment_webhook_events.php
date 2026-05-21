<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised lookup columns so parakit:webhooks:replay can reconstruct a
 * minimal WebhookPayload from a stored event row without depending on
 * gateway-specific shapes inside the JSON payload. The columns are nullable
 * because pre-0.9.1 rows do not have them — those rows can't be auto-replayed
 * but the migration is non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $t) {
            $t->string('gateway_transaction_id')->nullable()->after('event_id');
            $t->string('reference')->nullable()->after('gateway_transaction_id');
            $t->unsignedBigInteger('amount')->nullable()->after('reference');
            $t->string('currency', 3)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $t) {
            $t->dropColumn(['gateway_transaction_id', 'reference', 'amount', 'currency']);
        });
    }
};
