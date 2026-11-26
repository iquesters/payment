<?php

namespace Iquesters\Payment\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Payment\Models\Payment;
use Iquesters\Payment\Models\PaymentMethod;

/**
 * Payments of commerce orders: the buyer sends proof (UTR and screenshot) →
 * the order is under review; an admin verifies (→ the order is paid) or
 * rejects (→ the buyer may send proof again); an admin records cash or a UPI
 * payment at the desk (verified at once).
 */
class PaymentService
{
    use Loggable;

    public function __construct(private OrderService $orders) {}

    /**
     * @throws \DomainException
     */
    public function submitProof(Order $order, ?int $userId, string $utr, ?UploadedFile $proof, ?string $paidOn = null): Payment
    {
        if (! $order->isPayable()) {
            throw new \DomainException($order->isPaid() ? 'This order is already paid.' : 'This order is '.strtolower($order->statusLabel()).'.');
        }
        $utr = self::normaliseUtr($utr);
        $this->assertUtrFree($order->organisation_id, $utr);
        $method = PaymentMethod::upiFor($order->organisation_id);

        $payment = DB::transaction(function () use ($order, $userId, $utr, $proof, $paidOn, $method) {
            $payment = Payment::create([
                'organisation_id' => $order->organisation_id,
                'order_id' => $order->id,
                'payment_method_id' => $method?->id,
                'kind' => PaymentMethod::KIND_UPI,
                'amount' => $order->total,
                'currency' => $order->currency,
                'utr' => $utr ?: null,
                'paid_on' => $paidOn ?: now()->toDateString(),
                'status' => Payment::STATUS_SUBMITTED,
                'submitted_by' => $userId,
            ]);
            if ($proof) {
                $path = $proof->store(Payment::PROOF_DIRECTORY.'/'.$payment->uid, Payment::PROOF_DISK);
                $payment->update([
                    'proof_path' => $path,
                    'proof_name' => mb_substr($proof->getClientOriginalName(), 0, 255),
                    'proof_mime' => $proof->getMimeType(),
                    'proof_size' => $proof->getSize(),
                ]);
            }
            $this->orders->markUnderReview($order, 'Payment sent'.($utr ? " (UTR {$utr})" : ''), $userId);

            return $payment;
        });
        static::logInfo('Payment proof sent', ['order' => $order->reference, 'payment' => $payment->uid]);

        return $payment->fresh();
    }

    /** The payment matches the bank statement: the order is paid. */
    public function verify(Payment $payment, int $adminId, ?string $note = null): Payment
    {
        if ($payment->status !== Payment::STATUS_SUBMITTED) {
            throw new \DomainException('This payment is already '.strtolower($payment->statusLabel()).'.');
        }
        DB::transaction(function () use ($payment, $adminId, $note) {
            $payment->update(['status' => Payment::STATUS_VERIFIED, 'note' => $note, 'verified_by' => $adminId, 'verified_at' => now()]);
            $this->orders->markPaid($payment->order, 'Payment verified'.($payment->utr ? " (UTR {$payment->utr})" : ''), $adminId);
        });
        static::logInfo('Payment verified', ['payment' => $payment->uid, 'by' => $adminId]);

        return $payment->fresh();
    }

    /** Not found / wrong amount: the buyer may send proof again. */
    public function reject(Payment $payment, string $reason, int $adminId): Payment
    {
        if ($payment->status !== Payment::STATUS_SUBMITTED) {
            throw new \DomainException('This payment is already '.strtolower($payment->statusLabel()).'.');
        }
        DB::transaction(function () use ($payment, $reason, $adminId) {
            $payment->update(['status' => Payment::STATUS_REJECTED, 'reason' => $reason, 'verified_by' => $adminId, 'verified_at' => now()]);
            $this->orders->reject($payment->order, $reason, $adminId);
        });
        static::logInfo('Payment rejected', ['payment' => $payment->uid, 'by' => $adminId]);

        return $payment->fresh();
    }

    /** An admin records cash or a UPI payment at the desk: verified at once, the order is paid. */
    public function markPaid(Order $order, string $kind, float $amount, int $adminId, ?string $utr = null, ?string $note = null): Payment
    {
        if ($order->isPaid()) {
            throw new \DomainException('This order is already paid.');
        }
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \DomainException('This order is cancelled.');
        }
        $utr = self::normaliseUtr((string) $utr);
        $this->assertUtrFree($order->organisation_id, $utr);

        $payment = DB::transaction(function () use ($order, $kind, $amount, $adminId, $utr, $note) {
            $payment = Payment::create([
                'organisation_id' => $order->organisation_id,
                'order_id' => $order->id,
                'payment_method_id' => PaymentMethod::query()->where('organisation_id', $order->organisation_id)->where('kind', $kind)->value('id'),
                'kind' => $kind,
                'amount' => round($amount, 2),
                'currency' => $order->currency,
                'utr' => $utr ?: null,
                'paid_on' => now()->toDateString(),
                'status' => Payment::STATUS_VERIFIED,
                'note' => $note,
                'submitted_by' => $adminId,
                'verified_by' => $adminId,
                'verified_at' => now(),
            ]);
            // Pending proof sent earlier for the same order is superseded.
            Payment::query()->where('order_id', $order->id)->where('status', Payment::STATUS_SUBMITTED)
                ->update(['status' => Payment::STATUS_REJECTED, 'reason' => 'Paid at the desk', 'verified_by' => $adminId, 'verified_at' => now()]);
            $this->orders->markPaid($order, ($kind === PaymentMethod::KIND_CASH ? 'Paid in cash' : 'Paid by UPI at the desk').($note ? ": {$note}" : ''), $adminId);

            return $payment;
        });
        static::logInfo('Payment recorded by an admin', ['order' => $order->reference, 'kind' => $kind, 'by' => $adminId]);

        return $payment;
    }

    /** "1234 5678 9012" → "123456789012". */
    public static function normaliseUtr(?string $utr): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $utr));
    }

    private function assertUtrFree(int $organisationId, string $utr): void
    {
        if ($utr !== '' && Payment::query()->where('organisation_id', $organisationId)->where('utr', $utr)->where('status', '!=', Payment::STATUS_REJECTED)->exists()) {
            throw new \DomainException('This UTR was already used for another payment. Check the number, or contact the organisers.');
        }
    }
}
