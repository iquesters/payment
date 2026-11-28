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
        $params = array_filter([
            'pa' => self::normalise($method->setting('upi_id')),
            'pn' => trim((string) $method->setting('payee_name')),
            'am' => number_format((float) $order->total, 2, '.', ''),
            'cu' => $order->currency ?: 'INR',
            'tn' => (string) $order->reference,
        ], fn ($value) => $value !== '');

        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key.'='.self::encode($value);
        }

        return 'upi://pay?'.implode('&', $query);
    }

    /**
     * Encoded for the link, except "@": UPI apps (Google Pay among them) take
     * the address as written, so "name%40okaxis" is shown as is and the payment
     * fails. Spaces and other characters stay encoded (%20…).
     */
    private static function encode(string $value): string
    {
        return str_replace('%40', '@', rawurlencode($value));
    }

    /**
     * The UPI ID as UPI apps want it: no spaces (a mobile number is often typed
     * "98765 43210@ybl"), lower case ("@YBL" → "@ybl").
     */
    public static function normalise(?string $upiId): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', (string) $upiId));
    }

    /** A UPI ID looks like name@bank or mobile@bank (letters, digits, . _ -). */
    public static function isValidId(string $upiId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/', trim($upiId));
    }
}
