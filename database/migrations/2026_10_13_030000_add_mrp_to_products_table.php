<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retail price printed on the pack.
 *
 * `sale_price` is the trade price — what the outlet pays us — and it was the
 * only price a rep could see. So when a shopkeeper asked what the pack sells
 * for, the rep had no answer on screen; the figure lives on the packaging and
 * nowhere in the system.
 *
 * Nullable, because the 76 products already in the catalogue have no MRP
 * recorded and inventing one would be worse than leaving it blank. The screens
 * say "not set" until somebody fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('mrp', 10, 2)->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('mrp');
        });
    }
};
