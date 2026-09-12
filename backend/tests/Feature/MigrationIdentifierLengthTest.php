<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every index and foreign key name fits in MySQL.
 *
 * The tests run on SQLite, which accepts an identifier of any length, and
 * production runs MySQL, which stops at 64. That gap let a 68-character index
 * name through every test and into a failed deploy. This closes it: the
 * schema the tests just built is checked against the limit the server will
 * enforce.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_LIMIT = 64;

    public function test_no_index_or_foreign_key_name_is_longer_than_mysql_allows(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing() as $table) {
            // Some drivers qualify the name with its schema; MySQL does not count that.
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            foreach (Schema::getIndexes($table) as $index) {
                if ($index['primary'] ?? false) {
                    continue;
                }
                if (strlen($index['name']) > self::MYSQL_LIMIT) {
                    $tooLong[] = "index {$index['name']} (" . strlen($index['name']) . ')';
                }
            }

            /*
             * SQLite does not keep a foreign key's name, so it is worked out
             * the way Laravel names one - {table}_{columns}_foreign - which is
             * what MySQL will be asked to create.
             */
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $name = $foreign['name']
                    ?: strtolower($table . '_' . implode('_', $foreign['columns']) . '_foreign');

                if (strlen($name) > self::MYSQL_LIMIT) {
                    $tooLong[] = "foreign key {$name} (" . strlen($name) . ')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'MySQL refuses identifiers over ' . self::MYSQL_LIMIT . ' characters.');
    }

    public function test_the_birthday_migration_recovers_from_its_own_half_finished_first_run(): void
    {
        // The state production was left in: the table there, the migration unrecorded.
        Schema::drop('crm_birthday_wishes');
        Schema::create('crm_birthday_wishes', function ($table) {
            $table->id();
            $table->string('message', 1000);
        });

        $migration = require database_path('migrations/2026_09_28_100000_a_birthday_wish_reaches_the_person_and_can_be_answered.php');
        $migration->up();

        $names = collect(Schema::getIndexes('crm_birthday_wishes'))->pluck('name');
        $this->assertTrue($names->contains('crm_birthday_wishes_once_a_year'));
        $this->assertTrue($names->contains('crm_birthday_wishes_org_to_year'));
        $this->assertTrue(Schema::hasColumn('crm_birthday_wishes', 'reply'));
    }

    public function test_a_leftover_table_holding_wishes_is_not_dropped(): void
    {
        Schema::drop('crm_birthday_wishes');
        Schema::create('crm_birthday_wishes', function ($table) {
            $table->id();
            $table->string('message', 1000);
        });
        DB::table('crm_birthday_wishes')->insert(['message' => 'Happy birthday!']);

        $migration = require database_path('migrations/2026_09_28_100000_a_birthday_wish_reaches_the_person_and_can_be_answered.php');

        try {
            $migration->up();
            $this->fail('A table holding wishes must not be dropped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('holds 1 row', $e->getMessage());
        }

        $this->assertSame(1, DB::table('crm_birthday_wishes')->count());
    }
}
