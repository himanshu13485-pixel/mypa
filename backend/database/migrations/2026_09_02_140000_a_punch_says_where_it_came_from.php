<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a punch was made from.
 *
 * A time and an IP answer "when" and "roughly which network". They do not
 * answer the question an office actually has, which is whether the person
 * was at work — a laptop at home and a laptop at a desk look identical in
 * the register. These columns let a punch carry what it can prove: the kind
 * of device it came from, and, where the company asks for it, the place.
 *
 * All nullable: punches made before this, and punches where the person
 * declined the location prompt, stay perfectly valid records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_punches', function (Blueprint $table) {
            $table->string('in_device', 16)->nullable()->after('in_ip');
            $table->string('out_device', 16)->nullable()->after('out_ip');
            $table->decimal('in_lat', 10, 7)->nullable()->after('in_device');
            $table->decimal('in_lng', 10, 7)->nullable()->after('in_lat');
            // Metres from the office the company registered — null when the
            // company has not registered one, or the person declined.
            $table->unsignedInteger('in_distance_m')->nullable()->after('in_lng');
        });
    }

    public function down(): void
    {
        Schema::table('crm_punches', function (Blueprint $table) {
            $table->dropColumn(['in_device', 'out_device', 'in_lat', 'in_lng', 'in_distance_m']);
        });
    }
};
