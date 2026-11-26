<?php

namespace Iquesters\Payment\Support;

use Iquesters\Commerce\Models\Order;
use Iquesters\Payment\Models\PaymentMethod;

/**
 * The UPI payment link for an order: the organisation's UPI ID and payee name,
 * the order's exact amount, and its reference as the note (so it shows in the
 * bank statement). Shown as a QR and an "Open UPI app" link on phones.
 *
 *     upi://pay?pa=rahul.sen@okaxis&pn=TTV%20Alumni&am=1200.00&cu=INR&tn=TTV-MEET-0042
 */
class UpiLink
{
    public static function for(PaymentMethod $method, Order $order): string
    {
        $query = http_build_query(array_filter([
            'pa' => trim((string) $method->setting('upi_id')),
            'pn' => trim((string) $method->setting('payee_name')),
            'am' => number_format((float) $order->total, 2, '.', ''),
            'cu' => $order->currency ?: 'INR',
            'tn' => $order->reference,
        ], fn ($value) => $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return 'upi://pay?'.$query;
    }

    /** A UPI ID looks like name@bank (letters, digits, . _ -). */
    public static function isValidId(string $upiId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/', trim($upiId));
    }
}
