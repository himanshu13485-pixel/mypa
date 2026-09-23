<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people a mailbox writes to, kept.
 *
 * Every mail carries a name beside an address - "Chaudhari, Kunal
 * <Chaudhari.Kunal@bcg.com>" - and until now all of that was thrown away
 * the moment the message was filed. So the same address was typed out by
 * hand every time, and a colleague's name existed only inside whichever
 * mail happened to mention it.
 *
 * One row per address per person, with the best name seen for it, how often
 * it has been written to or heard from, and when it was last used - which
 * is what decides the order of the suggestions. Kept per member rather than
 * per company: an address book is a personal thing, and one person's
 * contacts are not the company's to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_mail_contacts')) {
            return;
        }

        Schema::create('crm_mail_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('email', 320);
            $table->string('name', 200)->nullable();
            // Somebody's own correction, which no arriving mail may overrule.
            $table->boolean('name_is_mine')->default(false);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('received_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            // Kept out of the suggestions without being forgotten.
            $table->boolean('is_blocked')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'email'], 'crm_mail_contact_unique');
            $table->index(['member_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_contacts');
    }
};
