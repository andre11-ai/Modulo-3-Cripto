<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m3_orden_pago', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // ej. ord_01J9X8...
            $table->string('reservation_id', 64)->index();
            $table->string('buyer_id', 64)->index();
            $table->string('event_id', 64);
            $table->string('seat_id', 32);
            $table->unsignedBigInteger('amount_minor'); // Monto en centavos (ej. 125000)
            $table->string('currency', 3)->default('MXN');
            $table->string('status', 20)->default('PENDING')->index(); // PENDING, PAID, FAILED, EXPIRED
            $table->string('buyer_email')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m3_orden_pago');
    }
};
