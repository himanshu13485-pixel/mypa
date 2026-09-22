<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared hosting, and the certificate it actually presents.
 *
 * A mailbox on shared hosting is reached at mail.theirdomain.com, but the
 * server answers with the hosting company's own certificate -
 * *.web-hosting.com, *.hostgator.com, and so on - so a strict check fails
 * on a mailbox that is otherwise perfectly fine.
 *
 * The honest fix is to connect to the name on the certificate. Where that
 * is not on offer, this lets one mailbox be told to accept it anyway: still
 * encrypted, but no longer proving who it is talking to. Off by default,
 * and per mailbox, so nobody loosens anything they did not mean to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_accounts', 'verify_cert')) {
                $table->boolean('verify_cert')->default(true)->after('smtp_password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_accounts', 'verify_cert')) {
                $table->dropColumn('verify_cert');
            }
        });
    }
};
