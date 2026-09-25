<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox keeps its own labels, its own addresses and its own filters.
 *
 * Labels and remembered addresses belonged to the person, so somebody
 * holding two mailboxes saw one pile: Company Admin's clients listed under
 * ZMA's Addresses, an "Invoices" label meant for one showing on both. A
 * mailbox is a separate place of work - separate correspondents, separate
 * filing - and now reads as one. Choosing All mailboxes still shows the lot
 * together, which is the only view where mixing them is the point.
 *
 * Filters are new. A rule says what to look for - who it came from, what the
 * subject or the body says, whether it carries a file - and a match wears
 * the label without anybody filing it. The OTP that arrives eleven times a
 * day goes where it belongs on its own.
 *
 * Existing labels and addresses go to their owner's default mailbox, which
 * is the one they were made while reading. Anybody holding one mailbox sees
 * no change at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->belongTo('crm_mail_labels', 'name');
        $this->belongTo('crm_mail_contacts', 'email');

        if (! Schema::hasTable('crm_mail_filters')) {
            Schema::create('crm_mail_filters', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
                // Whose mail this sifts, and where a match is filed.
                $table->foreignId('mail_account_id')->constrained('crm_mail_accounts')->cascadeOnDelete();
                $table->foreignId('mail_label_id')->nullable()->constrained('crm_mail_labels')->nullOnDelete();

                // What to look for. Every filled-in test must pass; the ones
                // left blank are not asked about.
                $table->string('from_has', 320)->nullable();
                $table->string('to_has', 320)->nullable();
                $table->string('subject_has', 255)->nullable();
                $table->string('body_has', 255)->nullable();
                $table->string('body_lacks', 255)->nullable();
                // null = do not care, true = only with, false = only without.
                $table->boolean('has_attachment')->nullable();
                $table->enum('size_op', ['gt', 'lt'])->nullable();
                $table->unsignedInteger('size_kb')->nullable();

                // What to do besides labelling it.
                $table->boolean('mark_read')->default(false);
                $table->boolean('star')->default(false);
                $table->boolean('skip_inbox')->default(false);
                $table->boolean('never_spam')->default(false);

                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('matched_count')->default(0);
                $table->timestamp('last_matched_at')->nullable();
                $table->timestamps();

                $table->index(['mail_account_id', 'is_active']);
            });
        }
    }

    /**
     * Hand a person-wide table to the mailbox each row was made in.
     *
     * The uniqueness moves with it: two mailboxes may each hold a label
     * called Invoices, which is the whole point of separating them.
     */
    private function belongTo(string $table, string $unique): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'mail_account_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('mail_account_id')->nullable()->after('member_id')
                ->constrained('crm_mail_accounts')->cascadeOnDelete();
        });

        // Their owner's default mailbox, or failing that the first one they
        // were given - which is the mailbox they were looking at.
        if (Schema::hasTable('crm_mail_accounts')) {
            foreach (DB::table($table)->select('member_id')->distinct()->pluck('member_id') as $memberId) {
                $account = DB::table('crm_mail_accounts')
                    ->where('member_id', $memberId)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');

                if ($account) {
                    DB::table($table)->where('member_id', $memberId)->update(['mail_account_id' => $account]);
                }
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $unique) {
            $blueprint->dropUnique($table === 'crm_mail_contacts' ? 'crm_mail_contact_unique' : "{$table}_member_id_{$unique}_unique");
            $blueprint->unique(['member_id', 'mail_account_id', $unique], "{$table}_own_{$unique}_unique");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_filters');

        foreach (['crm_mail_labels' => 'name', 'crm_mail_contacts' => 'email'] as $table => $unique) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'mail_account_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $unique) {
                $blueprint->dropUnique("{$table}_own_{$unique}_unique");
                $blueprint->dropConstrainedForeignId('mail_account_id');
                $blueprint->unique(['member_id', $unique], $table === 'crm_mail_contacts' ? 'crm_mail_contact_unique' : "{$table}_member_id_{$unique}_unique");
            });
        }
    }
};
