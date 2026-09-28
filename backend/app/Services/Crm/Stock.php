<?php

namespace App\Services\Crm;

use App\Models\Crm\InventoryItem;
use App\Models\Crm\InventoryMove;
use App\Models\Crm\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * What a document does to the count in the room.
 *
 * One rule, applied the same way every time a document is touched: a tax
 * invoice that is final takes its lines out of stock, and anything else -
 * a draft, a cancelled invoice, a proforma - takes nothing. A proforma is
 * a quote; quoting for forty of something must not empty the shelf.
 *
 * This is written as a reconciliation rather than a deduction, which is
 * the only way it survives editing. Every save works out what the moves
 * for this document ought to be, compares that with the moves it already
 * has, and settles the difference. So a line changed from four to three
 * gives one back, a line deleted gives all of it back, a document
 * cancelled gives everything back, and the same document saved twice
 * changes nothing the second time. Nothing here needs to know which of
 * those just happened.
 */
class Stock
{
    /**
     * Bring the counts into line with what this document now says.
     *
     * @return array<string>  what could not be met, named for the person
     *                        who raised it: "Blue widget: 3 left, 5 needed".
     */
    public static function sync(Invoice $invoice): array
    {
        $wanted = self::wanted($invoice);
        $short = [];

        DB::transaction(function () use ($invoice, $wanted, &$short) {
            $existing = InventoryMove::where('invoice_id', $invoice->id)
                ->lockForUpdate()->get()->keyBy('inventory_item_id');

            foreach ($existing as $itemId => $move) {
                if (! isset($wanted[$itemId])) {
                    self::move($move->item, -(float) $move->qty);
                    $move->delete();
                }
            }

            foreach ($wanted as $itemId => $qty) {
                $item = InventoryItem::whereKey($itemId)->lockForUpdate()->first();
                if (! $item || ! $item->isCounted()) {
                    continue;
                }

                $already = isset($existing[$itemId]) ? -(float) $existing[$itemId]->qty : 0.0;
                $delta = $qty - $already;
                if (abs($delta) < 0.0005) {
                    continue;
                }

                /*
                 * Short, and sold anyway.
                 *
                 * A count is a record of what somebody last put in; the
                 * sale is a fact. Refusing to raise the invoice would stop
                 * the business to protect the bookkeeping, which is the
                 * wrong way round - so the stock goes negative, is said
                 * plainly to whoever raised it, and reads red on the
                 * screen until somebody counts the shelf.
                 */
                if ($delta > 0 && (float) $item->quantity < $delta) {
                    $short[] = $item->name . ': ' . rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.')
                        . ' left, ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' needed';
                }

                self::move($item, -$delta);

                InventoryMove::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'inventory_item_id' => $item->id],
                    [
                        'organization_id' => $invoice->organization_id,
                        'qty' => -$qty,
                        'reason' => 'invoice',
                        'note' => $invoice->number,
                        'created_by' => $invoice->updated_by ?: $invoice->created_by,
                    ],
                );
            }
        });

        return $short;
    }

    /** Everything this document has taken, handed back. */
    public static function release(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $moves = InventoryMove::where('invoice_id', $invoice->id)->lockForUpdate()->get();
            foreach ($moves as $move) {
                if ($move->item) {
                    self::move($move->item, -(float) $move->qty);
                }
                $move->delete();
            }
        });
    }

    /**
     * What this document ought to be holding, item by item.
     *
     * @return array<int, float>  item id => quantity out of stock
     */
    private static function wanted(Invoice $invoice): array
    {
        if ($invoice->kind !== 'invoice' || $invoice->status !== 'final') {
            return [];
        }

        $wanted = [];
        foreach ($invoice->items()->whereNotNull('inventory_item_id')->get() as $line) {
            $qty = (float) $line->qty;
            if ($qty <= 0) {
                continue;
            }
            // The same thing on two lines is one thing, twice.
            $wanted[$line->inventory_item_id] = ($wanted[$line->inventory_item_id] ?? 0) + $qty;
        }

        return $wanted;
    }

    /** The running total, moved. */
    private static function move(?InventoryItem $item, float $by): void
    {
        if (! $item || ! $item->isCounted()) {
            return;
        }

        $item->forceFill(['quantity' => round((float) $item->quantity + $by, 3)])->save();
    }
}
