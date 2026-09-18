<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has already been said about a work order running out.
 *
 * A renewal is only sold if somebody notices the validity ending, and an
 * office that notices it on the last day has already lost the conversation.
 * Three warnings go out ahead of each expiry — so there has to be a record
 * of which of the three has gone, or the daily sweep would send the same one
 * every morning until the date passed.
 *
 * Per work order rather than per document: an invoice carrying a
 * twelve-month listing and a three-month one runs out twice, and each ending
 * is its own conversation with the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_renewal_reminders')) {
            return;
        }

        Schema::create('crm_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('crm_invoice_items')->cascadeOnDelete();
            // Which of the warnings this was: 30 days out, 15, 7 - whatever
            // the company set. Kept as the number so a changed schedule
            // cannot make an old row unreadable.
            $table->unsignedSmallInteger('offset_days');
            $table->string('audience', 16);           // executive | client
            $table->string('channel', 16);            // notification | email
            $table->string('to_email')->nullable();
            $table->string('status', 16)->default('sent');
            $table->string('error', 500)->nullable();
            $table->date('expires_on');
            $table->timestamps();

            // The question the sweep asks every morning: has this one gone?
            $table->unique(
                ['invoice_item_id', 'offset_days', 'audience', 'channel'],
                'crm_renewal_reminders_once',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_renewal_reminders');
    }
};
