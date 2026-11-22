<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m3_transaccion_pago', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // ej. pay_01J9X8...
            $table->string('orden_pago_id', 64);
            $table->string('stripe_payment_intent_id', 128)->unique();
            $table->string('status', 32)->default('PENDING');
            $table->string('failure_reason')->nullable();
            $table->jsonb('gateway_metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('orden_pago_id')
                ->references('id')
                ->on('m3_orden_pago')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m3_transaccion_pago');
    }
};
