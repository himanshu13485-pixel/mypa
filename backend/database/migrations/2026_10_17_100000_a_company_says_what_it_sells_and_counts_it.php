<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company sells, and how much of it is left.
 *
 * Every invoice line has been free text until now - a plan name typed, or
 * picked from a list somebody keeps by hand. That suits a company selling
 * its time. A company selling things needs the other half: a list of what
 * it sells, what each one costs, what tax it carries, and how many are in
 * the room. Raising an invoice for four of something should leave four
 * fewer of it, without anybody remembering to go and say so.
 *
 * Three pieces:
 *
 *   crm_issuing_companies.sells  - services or products. A company selling
 *                                  services keeps a price list and no
 *                                  counts; one selling products counts.
 *   crm_inventory_items          - the list itself, per issuing company,
 *                                  because a domestic arm and an export arm
 *                                  sell different things out of different
 *                                  rooms.
 *   crm_inventory_moves          - every change to a count, and what caused
 *                                  it. The count on the item is the running
 *                                  total; this is why it says what it says.
 *
 * The moves table is what makes an invoice safe to edit. One row per item
 * per document, so re-saving a document corrects its move rather than
 * deducting a second time, and un-finalising or cancelling puts the stock
 * back by deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_issuing_companies', 'sells')) {
                // Services, because that is what every company on here was
                // doing before the question could be asked.
                $table->string('sells', 16)->default('services')->after('tax_required');
            }
        });

        if (! Schema::hasTable('crm_inventory_items')) {
            Schema::create('crm_inventory_items', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('issuing_company_id')->constrained('crm_issuing_companies')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 64)->nullable();
                $table->string('kind', 16)->default('product');
                $table->string('unit', 24)->nullable();
                $table->text('description')->nullable();
                $table->decimal('unit_price', 14, 2)->default(0);
                // The tax this thing carries, as the rate books quote it.
                // Whether that lands as CGST+SGST or as IGST is still the
                // place of supply's business, not the item's.
                $table->decimal('tax_rate', 6, 3)->nullable();
                // What is in the room. Null for a service: there is no
                // count of an hour, and a zero would read as "none left".
                $table->decimal('quantity', 14, 3)->nullable();
                $table->decimal('reorder_at', 14, 3)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // Named, because the one Laravel would build from three
                // columns and a suffix runs to seventy characters and
                // MySQL stops at sixty-four.
                $table->index(['organization_id', 'issuing_company_id', 'is_active'], 'crm_inventory_items_org_company_active_index');
                $table->unique(['issuing_company_id', 'name']);
            });
        }

        if (! Schema::hasTable('crm_inventory_moves')) {
            Schema::create('crm_inventory_moves', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('inventory_item_id')->constrained('crm_inventory_items')->cascadeOnDelete();
                // The document that caused it, where one did. An adjustment
                // somebody typed has none, and says why in the note.
                $table->foreignId('invoice_id')->nullable()->constrained('crm_invoices')->cascadeOnDelete();
                // Signed: out of the room is negative, into it is positive.
                $table->decimal('qty', 14, 3);
                $table->string('reason', 24)->default('invoice');
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['organization_id', 'inventory_item_id']);
                // One move per item per document: a document that is saved
                // again corrects its own move instead of deducting twice.
                $table->unique(['invoice_id', 'inventory_item_id']);
            });
        }

        Schema::table('crm_invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_invoice_items', 'inventory_item_id')) {
                // Nullable on purpose. A line typed by hand is still a line,
                // and every line raised before today is one of those.
                $table->foreignId('inventory_item_id')->nullable()->after('invoice_id')
                    ->constrained('crm_inventory_items')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('crm_invoice_items', 'inventory_item_id')) {
                $table->dropConstrainedForeignId('inventory_item_id');
            }
        });

        Schema::dropIfExists('crm_inventory_moves');
        Schema::dropIfExists('crm_inventory_items');

        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            if (Schema::hasColumn('crm_issuing_companies', 'sells')) {
                $table->dropColumn('sells');
            }
        });
    }
};
