<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('correlation_id')->index();
            $t->string('gateway', 32);
            $t->string('action', 32);
            $t->string('endpoint')->nullable();
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->json('request')->nullable();
            $t->json('response')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
