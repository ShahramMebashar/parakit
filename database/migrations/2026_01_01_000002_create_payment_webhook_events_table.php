<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('gateway', 32);
            $t->string('event_id');
            $t->string('status', 32);
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
