<?php

namespace Iquesters\Payment\Support;

use Illuminate\Support\Facades\Route;
use Iquesters\Payment\Models\Payment;

/**
 * The messages the payment module sends, when iquesters/notifications is
 * installed (without it nothing is sent): payment details received, payment
 * verified, payment rejected. The wording is the notifications module's
 * template (editable per organisation); these are the defaults.
 */
class PaymentNotifications
{
    private const TYPES = 'Iquesters\Notifications\Support\NotificationTypes';

    private const SENDER = 'Iquesters\Notifications\Notifications';

    public static function available(): bool
    {
        return class_exists(self::TYPES) && class_exists(self::SENDER);
    }

    public static function register(): void
    {
        if (! self::available()) {
            return;
        }
        $variables = [
            'name' => 'Payer name',
            'organisation' => 'Organisation',
            'reference' => 'Order number',
            'amount' => 'Amount',
            'for' => 'What it pays for (e.g. the event)',
            'pay_url' => 'Pay page link',
        ];
        $sample = ['name' => 'Rahul Sen', 'organisation' => 'TTV Alumni Association', 'reference' => 'ORD-0001', 'amount' => '₹1,900', 'for' => '4th Annual Alumni Meet', 'pay_url' => url('/pay/01ABCDEFGHJKMNPQRSTVWXYZ00')];
        $types = app(self::TYPES);

        $types->register('payment.under_review', [
            'label' => 'Payment details received',
            'description' => 'The payer sent the UTR and screenshot; the organisers will check them.',
            'module' => 'payment',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'We received your payment details for {{reference}}',
                'body' => "Dear {{name}},\n\nThank you: we received your payment details for **{{reference}}** ({{amount}}, {{for}}).\n\nThe organisers will check them against their bank statement and confirm by email. You can see the status any time on the [payment page]({{pay_url}}).\n\n{{organisation}}",
            ]],
        ]);
        $types->register('payment.verified', [
            'label' => 'Payment confirmed',
            'description' => 'The organisers verified the payment (or recorded it at the desk): the order is paid.',
            'module' => 'payment',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'Payment confirmed: {{reference}}',
                'body' => "Dear {{name}},\n\nYour payment of **{{amount}}** for **{{reference}}** ({{for}}) is confirmed. Thank you!\n\n[See your order]({{pay_url}})\n\n{{organisation}}",
            ]],
        ]);
        $types->register('payment.rejected', [
            'label' => 'Payment not accepted',
            'description' => 'The organisers rejected the payment details; the payer can send them again.',
            'module' => 'payment',
            'variables' => $variables + ['reason' => 'Why it was not accepted'],
            'sample' => $sample + ['reason' => 'Not in the bank statement yet'],
            'defaults' => ['email' => [
                'subject' => 'Payment for {{reference}} not accepted',
                'body' => "Dear {{name}},\n\nThe payment details you sent for **{{reference}}** ({{amount}}) were not accepted:\n\n> {{reason}}\n\nPlease check and [send the details again]({{pay_url}}). If you have already paid, reply to this email.\n\n{{organisation}}",
            ]],
        ]);
    }

    /** Send one of the payment messages to the order's buyer. */
    public static function send(string $type, Payment $payment): void
    {
        if (! self::available()) {
            return;
        }
        $order = $payment->order()->with('organisation')->first();
        if (! $order) {
            return;
        }
        $money = ($order->currency === 'INR' ? '₹' : $order->currency.' ').number_format((float) $order->total, 2);
        $buyer = $order->user_id ? \Illuminate\Support\Facades\DB::table('users')->where('id', $order->user_id)->first(['name', 'email']) : null;

        (self::SENDER)::send($type, $order->organisation_id, [
            'email' => $order->buyer_email ?: $buyer?->email,
            'name' => $order->buyer_name ?: $buyer?->name,
            'user_id' => $order->user_id,
        ], [
            'name' => $order->buyer_name ?: ($buyer?->name ?? ''),
            'organisation' => $order->organisation?->name ?? '',
            'reference' => $order->reference,
            'amount' => $money,
            'for' => (string) ($order->notes ?? ''),
            'pay_url' => Route::has('payment.pay') ? route('payment.pay', $order->uid) : '',
            'reason' => (string) ($payment->reason ?? ''),
        ], $payment);
    }
}
