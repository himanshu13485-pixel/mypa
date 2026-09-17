<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remarks kept beside pay, for both registers that have any.
 *
 * The payroll and the offline list are different screens with different
 * people in them, but the act is one act: somebody answering, next to a
 * figure, a question that would otherwise be asked again every month. One
 * table, one set of rules, so the two cannot drift into behaving differently
 * for no reason anybody could explain.
 *
 * Kept against the person and the month rather than the slip, because
 * recalculating a slip deletes it and builds a fresh one - a remark tied to
 * the row would go with it without a word.
 */
class PayrollNotes
{
    /** The company payroll. */
    public const SALARY = 'salary';

    /** People paid outside it, who have a register of their own. */
    public const OFFLINE = 'offline';

    /** Which column names the person, for each register. */
    private const PERSON = [
        self::SALARY => 'member_id',
        self::OFFLINE => 'offline_employee_id',
    ];

    /** Where one remark lives: a person in a month, or the month itself. */
    public static function key(int $year, int $month, ?int $personId): string
    {
        return $year . '-' . $month . '-' . ($personId ?: 'month');
    }

    /**
     * Every remark belonging to the given months, ready to hand out.
     *
     * One query for the lot, month-level rows included, because a register
     * read across a span asks about several months at once.
     *
     * @param  iterable<array{0: int, 1: int}>  $periods  [year, month] pairs
     * @return Collection<string, list<array<string, mixed>>>
     */
    public static function forPeriods(int $orgId, string $scope, iterable $periods): Collection
    {
        $wanted = collect($periods)->unique(fn ($p) => $p[0] . '-' . $p[1]);
        if ($wanted->isEmpty()) {
            return collect();
        }

        $person = self::PERSON[$scope];

        return DB::table('crm_salary_notes')
            ->leftJoin('users', 'users.id', '=', 'crm_salary_notes.created_by')
            ->where('crm_salary_notes.organization_id', $orgId)
            ->where('crm_salary_notes.scope', $scope)
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as [$year, $month]) {
                    $q->orWhere(fn ($p) => $p->where('crm_salary_notes.year', $year)->where('crm_salary_notes.month', $month));
                }
            })
            ->orderBy('crm_salary_notes.id')
            ->get([
                'crm_salary_notes.id', 'crm_salary_notes.year', 'crm_salary_notes.month',
                'crm_salary_notes.' . $person . ' as person_id',
                'crm_salary_notes.body', 'crm_salary_notes.created_at', 'users.name as author',
            ])
            ->groupBy(fn ($n) => self::key((int) $n->year, (int) $n->month, $n->person_id ? (int) $n->person_id : null))
            ->map(fn ($group) => $group->map(fn ($n) => [
                'id' => (int) $n->id,
                'body' => $n->body,
                'author' => $n->author ?: 'Someone',
                'at' => Carbon::parse($n->created_at)->toDateTimeString(),
            ])->values()->all());
    }

    /** Write one remark. Null person means the month itself. */
    public static function add(int $orgId, string $scope, int $year, int $month, ?int $personId, string $body, ?int $userId): int
    {
        return DB::table('crm_salary_notes')->insertGetId([
            'organization_id' => $orgId,
            'scope' => $scope,
            'year' => $year,
            'month' => $month,
            self::PERSON[$scope] => $personId,
            'body' => trim($body),
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The same remark against several people at once.
     *
     * "Paid from the ICICI account" is true of eleven of them and false of
     * the other four; typing it eleven times is how it ends up typed eight.
     *
     * @param  iterable<array{0: int, 1: int, 2: int}>  $people  [year, month, personId]
     */
    public static function addMany(int $orgId, string $scope, iterable $people, string $body, ?int $userId): int
    {
        $body = trim($body);
        $now = now();
        $rows = collect($people)->map(fn ($p) => [
            'organization_id' => $orgId,
            'scope' => $scope,
            'year' => $p[0],
            'month' => $p[1],
            self::PERSON[$scope] => $p[2],
            'body' => $body,
            'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('crm_salary_notes')->insert($rows);
        }

        return count($rows);
    }

    /** One remark of this company's, whichever register it belongs to. */
    public static function find(int $orgId, int $id, ?string $scope = null): ?object
    {
        return DB::table('crm_salary_notes')
            ->where('organization_id', $orgId)
            ->when($scope, fn ($q) => $q->where('scope', $scope))
            ->where('id', $id)
            ->first();
    }

    public static function remove(int $id): void
    {
        DB::table('crm_salary_notes')->where('id', $id)->delete();
    }
}
