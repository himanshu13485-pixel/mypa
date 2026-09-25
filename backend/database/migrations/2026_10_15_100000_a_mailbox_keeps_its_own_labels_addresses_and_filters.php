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
     *
     * Done one step at a time, each step asking whether it is already done.
     * A migration that alters a table is not wrapped in a transaction by
     * MySQL - every statement is committed as it runs - so a failure halfway
     * leaves the table half-changed and the migration unrecorded, and the
     * next attempt has to pick up where the last one stopped.
     */
    private function belongTo(string $table, string $unique): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'mail_account_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('mail_account_id')->nullable()->after('member_id')
                    ->constrained('crm_mail_accounts')->cascadeOnDelete();
            });
        }

        // Their owner's default mailbox, or failing that the first one they
        // were given - which is the mailbox they were looking at. Only rows
        // that have not been given one already.
        if (Schema::hasTable('crm_mail_accounts')) {
            $waiting = DB::table($table)->whereNull('mail_account_id')->select('member_id')->distinct()->pluck('member_id');
            foreach ($waiting as $memberId) {
                $account = DB::table('crm_mail_accounts')
                    ->where('member_id', $memberId)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');

                if ($account) {
                    DB::table($table)
                        ->where('member_id', $memberId)
                        ->whereNull('mail_account_id')
                        ->update(['mail_account_id' => $account]);
                }
            }
        }

        /*
         * The new index goes on before the old one comes off.
         *
         * MySQL will not drop the last index a foreign key can use, and the
         * old unique on (member_id, name) was the only index carrying the
         * member_id key - so dropping it first is refused outright. The new
         * unique leads with member_id too, so once it exists the key has
         * somewhere else to stand and the old one can go.
         */
        $own = "{$table}_own_{$unique}_unique";
        $old = $table === 'crm_mail_contacts' ? 'crm_mail_contact_unique' : "{$table}_member_id_{$unique}_unique";

        if (! $this->hasIndex($table, $own)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['member_id', 'mail_account_id', $unique], $own));
        }
        if ($this->hasIndex($table, $old)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($old));
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn ($index) => strcasecmp((string) ($index['name'] ?? ''), $name) === 0);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_filters');

        foreach (['crm_mail_labels' => 'name', 'crm_mail_contacts' => 'email'] as $table => $unique) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'mail_account_id')) {
                continue;
            }

            $own = "{$table}_own_{$unique}_unique";
            $old = $table === 'crm_mail_contacts' ? 'crm_mail_contact_unique' : "{$table}_member_id_{$unique}_unique";

            // The same dance backwards: the old index is put back first, so
            // the member_id key still has one when the new index goes.
            if (! $this->hasIndex($table, $old)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['member_id', $unique], $old));
            }
            if ($this->hasIndex($table, $own)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($own));
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('mail_account_id'));
        }
    }
};
