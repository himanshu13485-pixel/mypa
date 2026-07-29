<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mobile-first identity: username handle + ISD country code.
            $table->string('username', 32)->nullable()->unique()->after('name');
            $table->string('country_code', 8)->nullable()->after('mobile'); // e.g. +91
            $table->timestamp('username_changed_at')->nullable()->after('mobile_verified_at');
            // Email becomes optional (login is by mobile, username, or email).
            $table->string('email')->nullable()->change();
            // Mobile becomes a unique login identifier (stored with ISD prefix).
            $table->unique('mobile');
        });

        // In-app OTPs for mobile verification (delivered app-to-app, no SMS gateway).
        Schema::create('mobile_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mobile', 40);
            $table->string('code', 8);
            $table->string('purpose', 32)->default('verify_mobile');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose']);
        });

        // Identity change requests needing Admin/Subadmin approval.
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['mobile', 'email', 'username']);
            $table->string('current_value')->nullable();
            $table->string('new_value');
            $table->string('country_code', 8)->nullable(); // for mobile changes
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type', 'status']);
        });

        // Admin-editable application settings (e.g. username change cooldown).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('value', 500)->nullable();
            $table->timestamps();
        });

        // "Attended" tracking for missed calls (sidebar badge).
        Schema::table('call_participants', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_participants', fn (Blueprint $table) => $table->dropColumn('seen_at'));
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('mobile_otps');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['mobile']);
            $table->dropColumn(['username', 'country_code', 'username_changed_at']);
        });
    }
};
