<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ring me again in twenty minutes."
 *
 * A task or a bill falling due now says so out loud, and the answer to an
 * alarm is rarely "done" - it is "not this second". Without somewhere to
 * write that down the only way to stop it was to finish the thing or to
 * leave the alarm ringing every time the page was opened.
 *
 * On the item rather than in a table of its own, because it is one fact
 * about one thing, and because both modules then answer the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tasks', 'bills'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'alert_snoozed_until')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dateTime('alert_snoozed_until')->nullable();
                // And when the phone was last told, so an alarm the person
                // has seen does not buzz them every few minutes afterwards.
                $blueprint->dateTime('alert_pushed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['tasks', 'bills'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'alert_snoozed_until')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn(['alert_snoozed_until', 'alert_pushed_at']);
                });
            }
        }
    }
};
