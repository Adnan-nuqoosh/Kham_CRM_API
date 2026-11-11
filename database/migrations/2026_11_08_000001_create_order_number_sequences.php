<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Preserve numbering if the CRM already has orders from earlier years.
        // Do not alter or delete any existing order.
        $maximumByYear = [];
        DB::table('orders')->select(['id', 'order_number'])->orderBy('id')
            ->chunkById(1000, function ($orders) use (&$maximumByYear): void {
                foreach ($orders as $order) {
                    if (! preg_match('/^KH-([0-9]{4})-([0-9]+)$/', $order->order_number, $matches)) {
                        continue;
                    }
                    $year = (int) $matches[1];
                    $number = (int) $matches[2];
                    $maximumByYear[$year] = max($maximumByYear[$year] ?? 0, $number);
                }
            });

        foreach ($maximumByYear as $year => $lastNumber) {
            DB::table('order_number_sequences')->insert([
                'year' => $year,
                'last_number' => $lastNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
