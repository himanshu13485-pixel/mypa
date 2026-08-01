<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Waiting room: joiners must be admitted by the host (bypassable).
            $table->boolean('requires_approval')->default(false)->after('is_screen');
        });

        // Participant status gains waiting/admitted/denied states.
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->string('status', 16)->default('joined')->change();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
    }
};
