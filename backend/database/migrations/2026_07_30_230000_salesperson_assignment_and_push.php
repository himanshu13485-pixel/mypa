<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Which salesperson looks after this user (nullable — most users have none).
            $table->foreignId('salesperson_id')->nullable()->after('remember_token')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique(); // sha256(endpoint) — endpoint itself is too long to index
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salesperson_id');
        });
    }
};
