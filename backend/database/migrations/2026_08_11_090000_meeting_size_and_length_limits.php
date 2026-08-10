<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

/**
 * Give the plans that already exist a meeting size and a meeting length.
 *
 * No schema change: limits is a JSON column, so the keys can simply appear.
 * But the seeder only runs on a fresh install, and production has had these
 * plans since launch — without this, every live plan would report null for
 * both, which means unlimited, and the caps would be live in the code and
 * absent from the data.
 *
 * Only fills in what is missing. An admin who has already set a value in the
 * plan editor keeps it, so re-running this can never undo their work.
 */
return new class extends Migration
{
    /** Matches PlanSeeder. Anything not listed is left uncapped. */
    private const DEFAULTS = [
        'free' => ['max_meeting_participants' => 4, 'max_meeting_minutes' => 40],
        'personal' => ['max_meeting_participants' => 8, 'max_meeting_minutes' => 60],
        'family' => ['max_meeting_participants' => 16, 'max_meeting_minutes' => 120],
        'professional' => ['max_meeting_participants' => 50, 'max_meeting_minutes' => 300],
        'business' => ['max_meeting_participants' => 100, 'max_meeting_minutes' => null],
        'enterprise' => ['max_meeting_participants' => null, 'max_meeting_minutes' => null],
    ];

    public function up(): void
    {
        foreach (Plan::all() as $plan) {
            $limits = $plan->limits ?? [];
            $defaults = self::DEFAULTS[$plan->slug] ?? [
                'max_meeting_participants' => null,
                'max_meeting_minutes' => null,
            ];

            foreach ($defaults as $key => $value) {
                // array_key_exists, not ??=: null is a real setting here — it
                // means unlimited — and must not be mistaken for unset.
                if (! array_key_exists($key, $limits)) {
                    $limits[$key] = $value;
                }
            }

            $plan->update(['limits' => $limits]);
        }
    }

    public function down(): void
    {
        foreach (Plan::all() as $plan) {
            $limits = $plan->limits ?? [];
            unset($limits['max_meeting_participants'], $limits['max_meeting_minutes']);
            $plan->update(['limits' => $limits]);
        }
    }
};
