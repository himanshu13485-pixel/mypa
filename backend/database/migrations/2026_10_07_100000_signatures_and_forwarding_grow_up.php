<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things every mail program has that ours did not.
 *
 * A signature that knows where it belongs - on new mail, on replies, above
 * the quoted thread rather than under it - and forwarding to addresses that
 * have proved they want the mail: a code is sent to each one, and nothing is
 * forwarded anywhere until somebody types it back.
 *
 * Forwarding to an address nobody checked is how a mailbox quietly copies a
 * company's mail to a typo for a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_accounts', 'signature_reply_html')) {
                // A shorter one for replies, as most people want.
                $table->text('signature_reply_html')->nullable()->after('signature_html');
                // new | all | none - where the signature is put.
                $table->string('signature_on', 10)->default('all')->after('signature_reply_html');
                // Above the quoted mail, the way Gmail offers.
                $table->boolean('signature_before_quote')->default(true)->after('signature_on');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'forwards')) {
                // [{address, verified_at, code, code_sent_at, tries}]
                $table->json('forwards')->nullable()->after('forward_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            foreach (['signature_reply_html', 'signature_on', 'signature_before_quote', 'forwards'] as $column) {
                if (Schema::hasColumn('crm_mail_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
