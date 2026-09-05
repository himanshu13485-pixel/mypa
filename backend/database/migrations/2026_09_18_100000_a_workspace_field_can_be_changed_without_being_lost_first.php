<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Changing a workspace field without losing it first.
 *
 * A company that had switched Plan name to a dropdown and wanted to add one
 * more plan to it had exactly one way through: delete the customisation and
 * ask for a new one. Deleting took effect at once, so the column fell back to
 * plain text on every live document, and it stayed that way until the Super
 * Admin got round to the new request. The cost of adding a plan was losing
 * the list.
 *
 * The reason it worked that way is sound — one customisation per column, so
 * the live Work Order is never two competing definitions. What was missing is
 * somewhere to put a change that has been asked for but not yet allowed.
 *
 * That is this column. The row keeps its approved values and stays live;
 * `pending` holds what the company would like it to become. Approving writes
 * the proposal into the row and empties this; rejecting empties it and the
 * row carries on exactly as it was. Nothing is live because somebody asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_custom_fields', function (Blueprint $table) {
            // Null is the ordinary state: no change outstanding.
            $table->json('pending')->nullable()->after('status');
            // Who asked and when, so the Super Admin's queue can say "changed
            // 2 days ago" about a field approved last year.
            $table->foreignId('pending_by')->nullable()->after('pending')
                ->constrained('crm_members')->nullOnDelete();
            $table->timestamp('pending_at')->nullable()->after('pending_by');
        });
    }

    public function down(): void
    {
        Schema::table('crm_custom_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_by');
            $table->dropColumn(['pending', 'pending_at']);
        });
    }
};
