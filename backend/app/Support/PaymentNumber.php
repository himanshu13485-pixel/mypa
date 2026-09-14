<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Payment ids: PAY-000123.
 *
 * One series across the Payments inbox and invoice payments, because a
 * receipt keeps its id when it is settled onto an invoice - two separate
 * counters would hand out the same id twice, once on each side.
 */
final class PaymentNumber
{
    public const PREFIX = 'PAY-';

    public static function next(): string
    {
        $highest = 0;

        foreach (['crm_payment_inbox', 'crm_invoice_payments'] as $table) {
            // Zero-padded, so the longest and then the greatest is the highest.
            $last = DB::table($table)
                ->where('payment_no', 'like', self::PREFIX . '%')
                ->orderByRaw('length(payment_no) desc')
                ->orderByDesc('payment_no')
                ->value('payment_no');

            if ($last) {
                $highest = max($highest, (int) substr($last, strlen(self::PREFIX)));
            }
        }

        return self::PREFIX . str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Give a freshly created row the next id.
     *
     * Two receipts logged in the same instant could both be offered the same
     * id; the unique index refuses the second, and it simply asks again.
     */
    public static function assign(Model $model): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $model->forceFill(['payment_no' => self::next()])->saveQuietly();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
