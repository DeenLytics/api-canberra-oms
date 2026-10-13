<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order discounts.
 *
 * This table was created by hand on production and never recorded in the
 * `migrations` table, so it sat "Pending" with 348 live rows in it. Any
 * `php artisan migrate` would therefore reach this file, try to CREATE a table
 * that already exists, fail, and abort the whole batch — which is why the MRP
 * migration had to be run on its own with `--path`. One unrecorded hand-made
 * table had blocked every future migration on this install.
 *
 * So the create is guarded. On production it does nothing and gets recorded, on
 * a fresh database it builds the table as normal, and `migrate` works again
 * either way. No schema change is applied to the live table.
 *
 * `value` is decimal(10,2) because that is what the live table has. The file
 * previously said 12,2, which differed from production for no reason — a
 * discount of a hundred million taka is not a case worth planning for, and a
 * migration that disagrees with the database it ran against is a trap for the
 * next person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discounts')) {
            return;
        }

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->decimal('value', 10, 2)->nullable();
            $table->enum('type', ['percentage', 'fixed'])->nullable();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
