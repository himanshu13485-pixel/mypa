<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an approval request is about.
 *
 * Most are about a document — a price agreed below the card rate, details to
 * be resent, an error to be waived — and those must name the invoice or at
 * least the client, so whoever decides is not guessing. The rest are the
 * office's own money: a mobile recharge paid from someone's pocket and
 * claimed back. Those name nothing, and that is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_approvals', function (Blueprint $table) {
            // invoice | general — the shape of the request, not its type.
            $table->string('scope', 16)->default('general')->after('type');
            $table->foreignId('client_id')->nullable()->after('invoice_id')
                ->constrained('crm_clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('scope');
        });
    }
};
