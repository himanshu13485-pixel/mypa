<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that hands labels and addresses to a mailbox, run twice.
 *
 * It altered three things in a row, and MySQL commits each alteration as it
 * runs - no transaction wraps them. So when the middle step was refused on
 * the live database, the table was left half-changed and the migration
 * unrecorded: the next attempt found the column already there, skipped the
 * whole block, and would have left the old index in place for ever.
 *
 * Every step now asks whether it is already done, which is what makes the
 * second attempt finish the job rather than step over it.
 */
class MailOwnLabelsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_100000_a_mailbox_keeps_its_own_labels_addresses_and_filters.php');
    }

    private function indexes(string $table): array
    {
        return array_map(
            fn ($index) => strtolower((string) ($index['name'] ?? '')),
            Schema::getIndexes($table),
        );
    }

    public function test_running_it_again_finishes_the_job_instead_of_stepping_over_it(): void
    {
        // Already applied once by the test database's own migration run.
        foreach (['crm_mail_labels', 'crm_mail_contacts'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'mail_account_id'));
        }

        // A second run must be harmless - and must leave the same shape.
        $this->migration()->up();

        $this->assertContains('crm_mail_labels_own_name_unique', $this->indexes('crm_mail_labels'));
        $this->assertNotContains('crm_mail_labels_member_id_name_unique', $this->indexes('crm_mail_labels'));
        $this->assertContains('crm_mail_contacts_own_email_unique', $this->indexes('crm_mail_contacts'));
        $this->assertNotContains('crm_mail_contact_unique', $this->indexes('crm_mail_contacts'));
        $this->assertTrue(Schema::hasTable('crm_mail_filters'));
    }

    public function test_the_member_key_never_stands_without_an_index(): void
    {
        /*
         * The whole reason the live run stopped. A foreign key needs an index
         * it can use, and the old unique was the only one carrying member_id
         * on the labels table - so dropping it first was refused. The new
         * unique leads with member_id, which is what lets the old one go.
         */
        $leading = collect(Schema::getIndexes('crm_mail_labels'))
            ->filter(fn ($index) => strtolower((string) (is_array($index['columns'] ?? null) ? ($index['columns'][0] ?? '') : '')) === 'member_id');

        $this->assertNotEmpty($leading, 'member_id must lead at least one index');
    }

    public function test_it_can_be_rolled_back_and_run_forward_again(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('crm_mail_labels', 'mail_account_id'));
        $this->assertContains('crm_mail_labels_member_id_name_unique', $this->indexes('crm_mail_labels'));
        $this->assertFalse(Schema::hasTable('crm_mail_filters'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('crm_mail_labels', 'mail_account_id'));
        $this->assertContains('crm_mail_labels_own_name_unique', $this->indexes('crm_mail_labels'));
        $this->assertTrue(Schema::hasTable('crm_mail_filters'));
    }
}
