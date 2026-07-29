<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The OTP target column now also carries pending email addresses.
        Schema::table('mobile_otps', function (Blueprint $table) {
            $table->string('mobile', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_otps', function (Blueprint $table) {
            $table->string('mobile', 40)->change();
        });
    }
};
