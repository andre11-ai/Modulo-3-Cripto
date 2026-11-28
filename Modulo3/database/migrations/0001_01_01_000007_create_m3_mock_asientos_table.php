<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m3_mock_asientos', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->default('evt_2026_001');
            $table->string('seat_id', 32)->unique();
            $table->string('status', 20)->default('FREE');
            $table->string('reservation_id', 64)->nullable()->index();
            $table->string('locked_by', 64)->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->unsignedBigInteger('amount_minor')->default(125000);
            $table->string('currency', 3)->default('MXN');
            $table->timestampsTz();
        });

        DB::table('m3_mock_asientos')->insert([
            [
                'event_id' => 'evt_2026_001',
                'seat_id' => 'A-12',
                'status' => 'FREE',
                'amount_minor' => 125000,
                'currency' => 'MXN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => 'evt_2026_001',
                'seat_id' => 'A-13',
                'status' => 'FREE',
                'amount_minor' => 125000,
                'currency' => 'MXN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => 'evt_2026_001',
                'seat_id' => 'A-14',
                'status' => 'SOLD',
                'amount_minor' => 125000,
                'currency' => 'MXN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m3_mock_asientos');
    }
};
