<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remarks that belong beside the figures.
 *
 * A P&L answers "how much" and never "why", so every month the same
 * questions come back - why were bank charges four lakh, what was the odd
 * income in the middle of August, why is this month unlike the last one.
 * The answers lived in somebody's head, or in a WhatsApp message nobody
 * could find again.
 *
 * A note hangs either on one entry (line_key) or on the month itself
 * (line_key null), and there can be as many as the month deserves.
 *
 * line_key is a name rather than a foreign key on purpose: most entries are
 * not rows anywhere. Gross sales, a category of the expense book and the
 * payroll are all worked out afresh each time the page is opened, so there
 * is no id to point at - only what the line is. See PlController::lineKey().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_pl_notes')) {
            return;
        }

        Schema::create('crm_pl_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->char('month', 7);                       // YYYY-MM
            $table->string('line_key', 190)->nullable();    // null = the month itself
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pl_notes');
    }
};
