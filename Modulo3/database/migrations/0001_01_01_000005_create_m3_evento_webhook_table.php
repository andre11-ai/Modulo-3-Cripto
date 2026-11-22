<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m3_evento_webhook', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id', 128)->unique(); // Llave de idempotencia
            $table->string('event_type', 100);
            $table->string('stripe_payment_intent_id', 128)->nullable()->index();
            $table->jsonb('payload');
            $table->boolean('processed')->default(false);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m3_evento_webhook');
    }
};
