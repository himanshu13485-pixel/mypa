<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two lead flags:
 *  - is_urgent: an urgent lead rides above every scheduled one, in the list
 *    and in the follow-up popup alike.
 *  - duplicate_settled_at: a duplicate lead is locked for employees until an
 *    Admin/Subadmin sorts it; settling stamps the moment and unlocks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('lead_status');
            $table->dateTime('duplicate_settled_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['is_urgent', 'duplicate_settled_at']);
        });
    }
};
