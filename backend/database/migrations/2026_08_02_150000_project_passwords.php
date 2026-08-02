<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Optional lock on the ledger. Reset codes are admin-issued.
            $table->string('password_hash')->nullable()->after('notes');
            $table->string('reset_code_hash')->nullable()->after('password_hash');
            $table->timestamp('reset_code_expires_at')->nullable()->after('reset_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'reset_code_hash', 'reset_code_expires_at']);
        });
    }
};
