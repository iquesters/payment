<?php

namespace Iquesters\Payment\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Payment\Models\PaymentMethod;

/** The payment module's form schemas (payment:seed rebuilds them). */
class PaymentForms
{
    public const PROOF = 'payment-proof';

    public const DECISION = 'payment-decision';

    public const MARK_PAID = 'payment-mark-paid';

    public const SETTINGS = 'payment-settings';

    public static function syncAll(): void
    {
        self::upsert(self::PROOF, 'Payment details', self::proof());
        self::upsert(self::DECISION, 'Verify a payment', self::decision());
        self::upsert(self::MARK_PAID, 'Record a payment', self::markPaid());
        self::upsert(self::SETTINGS, 'Payment settings', self::settings());
    }

    /** The payer, after paying by UPI: the UTR and the screenshot (form on the pay page, {uid} = the order). */
    public static function proof(): array
    {
        return [
            'endpoint' => '/pay/{uid}',
            'method' => 'POST',
            'submitButtonLabel' => 'Submit payment details',
            'submittingLabel' => 'Sending',
            // The pay page shows the message itself (flash).
            'disableSnackbar' => true,
            'allowCancel' => false,
            'defaultFieldSize' => 12,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'fields' => [
                ['id' => 'utr', 'label' => 'UPI transaction ID (UTR)', 'type' => 'text', 'required' => true, 'maxLength' => 32, 'placeholder' => 'e.g. 412345678901',
                    'helperText' => 'The 12-digit number in your UPI app\'s payment details (also called UTR or UPI Ref No.).'],
                ['id' => 'proof', 'label' => 'Screenshot of the payment', 'type' => 'file', 'required' => true, 'accept' => 'image/png,image/jpeg,image/webp,application/pdf',
                    'helperText' => 'JPG, PNG, WEBP or PDF.'],
                ['id' => 'paid_on', 'label' => 'Paid on', 'type' => 'date'],
            ],
        ];
    }

    /** Admins: verify or reject a payment sent ({uid} = the payment). */
    public static function decision(): array
    {
        return [
            'endpoint' => '/payment/payments/{uid}/decision',
            'method' => 'POST',
            'submitButtonLabel' => 'Save decision',
            'allowCancel' => false,
            'defaultFieldSize' => 12,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'fields' => [
                ['id' => 'decision', 'label' => 'Decision', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'verify', 'label' => 'Verify: the amount and UTR are in the bank statement'],
                    ['value' => 'reject', 'label' => 'Reject: the payer can send the details again'],
                ]],
                ['id' => 'note', 'label' => 'Note (required to reject; the payer sees it)', 'type' => 'textarea', 'rows' => 2, 'maxLength' => 1000],
            ],
        ];
    }

    /** Admins: cash or UPI at the desk, for an order by its reference. */
    public static function markPaid(): array
    {
        return [
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Record payment',
            'modes' => ['create' => ['endpoint' => '/payment/payments/mark-paid', 'method' => 'POST']],
            'fields' => [
                ['id' => 'reference', 'label' => 'Order reference', 'type' => 'text', 'required' => true, 'maxLength' => 48, 'placeholder' => 'e.g. TTV-MEET-0042'],
                ['id' => 'kind', 'label' => 'Paid by', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => PaymentMethod::KIND_CASH, 'label' => 'Cash'],
                    ['value' => PaymentMethod::KIND_UPI, 'label' => 'UPI (at the desk)'],
                ]],
                ['id' => 'amount', 'label' => 'Amount received', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
                ['id' => 'utr', 'label' => 'UTR (for UPI)', 'type' => 'text', 'maxLength' => 32],
                ['id' => 'note', 'label' => 'Note', 'type' => 'textarea', 'rows' => 2, 'maxLength' => 1000],
            ],
        ];
    }

    /** Admins: how the organisation takes money ({uid} = the organisation). */
    public static function settings(): array
    {
        return [
            'endpoint' => '/payment/settings/{uid}',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save payment settings',
            'defaultFieldSize' => 'auto',
            'modes' => ['view' => ['allowDelete' => false], 'edit' => ['allowDelete' => false]],
            'fields' => [
                ['id' => 'upi', 'type' => 'section', 'label' => 'UPI', 'description' => 'People pay by scanning a QR with the exact amount and the order reference. A person\'s UPI ID is fine until the organisation\'s bank account is ready.'],
                ['id' => 'upi_enabled', 'label' => 'Take payments by UPI', 'type' => 'checkbox'],
                ['id' => 'upi_id', 'label' => 'UPI ID', 'type' => 'text', 'maxLength' => 256, 'placeholder' => 'e.g. name@okaxis'],
                ['id' => 'payee_name', 'label' => 'Payee name (as in the UPI app)', 'type' => 'text', 'maxLength' => 100],
                ['id' => 'instructions', 'label' => 'Instructions shown with the QR', 'type' => 'textarea', 'rows' => 2, 'maxLength' => 1000],
                ['id' => 'desk', 'type' => 'section', 'label' => 'At the desk', 'description' => 'Admins record these payments themselves (Payments → Record a payment).'],
                ['id' => 'cash_enabled', 'label' => 'Take cash', 'type' => 'checkbox'],
                ['id' => 'policy', 'type' => 'section', 'label' => 'Refund policy', 'description' => 'Shown on the pay page.'],
                ['id' => 'refund_policy', 'label' => 'Refund and cancellation policy', 'type' => 'textarea', 'rows' => 4, 'maxLength' => 5000],
            ],
        ];
    }

    private static function upsert(string $slug, string $name, array $schema): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Payment module form ({$slug}); payment:seed rebuilds it",
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
