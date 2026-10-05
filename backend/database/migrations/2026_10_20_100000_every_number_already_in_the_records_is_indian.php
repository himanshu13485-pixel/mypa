<?php

use App\Support\DialCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saying, of every number already filed, where it was dialled from.
 *
 * The forms ask for a country now. Everything typed before they did was
 * entered by people working in one country who had no reason to say so, so
 * the old rows are the only ones that do not know where they are - and a
 * list where some numbers carry a code and some do not is worse than one
 * where none of them do. Nothing can tell those numbers apart afterwards.
 *
 * India, because that is where every one of them was typed.
 *
 * Numbers that already carry a code are not touched, and neither is anything
 * too short to be a phone number. The rule itself is in DialCode, where it
 * can be read and tested; this walks the tables and applies it.
 *
 * Duplicate detection is unaffected: clients and leads both match on the last
 * ten digits, which a country code in front does not change.
 */
return new class extends Migration
{
    /** Table to the columns on it that hold a phone number. */
    private const NUMBERS = [
        'crm_clients' => ['mobile', 'telephone'],
        'crm_leads' => ['mobile', 'phone'],
    ];

    public function up(): void
    {
        foreach (self::NUMBERS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $this->codify($table, $column);
            }
        }
    }

    /**
     * No down().
     *
     * Rolling this back would mean stripping "+91" from every number that
     * has one - including the ones somebody has typed since, deliberately,
     * for a client who really is in India. There is no way to tell those
     * apart from the ones this wrote, so the honest answer is not to pretend
     * it can be undone.
     */
    public function down(): void
    {
        //
    }

    private function codify(string $table, string $column): void
    {
        /*
         * In chunks, by id, because this runs against a live database during
         * a deploy. A single UPDATE over every client a business owns holds
         * a lock for as long as it takes; a few hundred rows at a time does
         * not, and it does not matter how long the whole thing takes.
         */
        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'not like', '+%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $coded = DialCode::addDefault($row->{$column});

                    if ($coded !== null) {
                        DB::table($table)->where('id', $row->id)->update([$column => $coded]);
                    }
                }
            });
    }
};
