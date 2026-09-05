<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * A company's name, in the address bar.
 *
 * Until now the CRM lived at /crm/leads for everybody, and which company you
 * were looking at was a value in localStorage. That is fine until somebody
 * has two of them — a Super Admin who entered a workspace, an accountant who
 * works for two firms — and then a pasted link means one company to the
 * person who sent it and a different one to the person who opens it. A URL
 * that does not say what it points at is not a link, it is a suggestion.
 *
 * So the company becomes a segment: /crm/bhavya-steel/leads. The slug is
 * what the browser shows and what the API is told to answer as, which makes
 * the address bar the authority on whose records are on screen.
 *
 * Why a column rather than slugifying the name on the way out: names are not
 * unique and they change. A stored slug can be made unique once, here, and a
 * company that renames itself keeps its links working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            // Nullable to begin with — the backfill below fills it, and only
            // then is it something every row has.
            $table->string('slug', 64)->nullable()->unique()->after('code');
        });

        $taken = [];

        DB::table('crm_organizations')->orderBy('id')->select('id', 'name', 'code')
            ->chunkById(200, function ($orgs) use (&$taken) {
                foreach ($orgs as $org) {
                    $slug = self::slugFor($org->name, $org->code, $org->id, $taken);
                    $taken[$slug] = true;

                    DB::table('crm_organizations')->where('id', $org->id)->update(['slug' => $slug]);
                }
            });
    }

    /**
     * A readable, unique, unreserved slug for one company.
     *
     * The fallbacks matter more than the happy path: a company named in a
     * script the slugger cannot transliterate, or named exactly the same as
     * another, or named "Reports", all still have to end up somewhere the
     * router can reach.
     */
    private static function slugFor(string $name, ?string $code, int $id, array $taken): string
    {
        $base = Str::slug($name) ?: Str::slug((string) $code) ?: 'company';
        $base = Str::limit($base, 48, '');

        // Words the CRM's own screens answer to. A company living at one of
        // them could never be opened — the route would win.
        $reserved = [
            'organizations', 'employees', 'clients', 'leads', 'lead-log', 'targets', 'dwr',
            'punch', 'payments', 'complaints', 'complaint-log', 'hr-policy', 'incentives',
            'vendors', 'expenses', 'salary', 'leaves', 'tasks', 'approvals', 'newsletters',
            'cms', 'user-log', 'reports', 'workspace-fields', 'field-requests', 'contests',
            'invoices', 'invoice-log', 'recurring', 'commissions', 'overview', 'settings',
            'connect', 'pl', 'assets', 'churn', 'communication', 'new', 'edit',
        ];

        if (in_array($base, $reserved, true)) {
            $base .= '-co';
        }

        $slug = $base;
        $n = 1;

        // Against both what this migration has already handed out and what
        // the table holds — the second matters when it is re-run on a
        // database where some rows are filled in already.
        while (isset($taken[$slug])
            || DB::table('crm_organizations')->where('slug', $slug)->where('id', '!=', $id)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
