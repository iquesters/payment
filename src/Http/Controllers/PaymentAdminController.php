<?php

namespace Iquesters\Payment\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Commerce\Models\Order;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Organisation\Support\OrganisationContext;
use Iquesters\Payment\Models\Payment;
use Iquesters\Payment\Models\PaymentMethod;
use Iquesters\Payment\Services\PaymentService;
use Iquesters\Payment\Support\PaymentAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The verification queue (generic table payment-table, rows from data()):
 * payments sent with their proof; Verify / Reject; Record a payment (cash or
 * UPI at the desk). Super-admins and org-admins, for their organisations.
 */
class PaymentAdminController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function data(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with(['order:id,uid,reference,total,currency,status,buyer_name,buyer_phone', 'organisation:id,name']);
        $status = (string) $request->query('status', '');
        if (in_array($status, array_keys(Payment::LABELS), true)) {
            $query->where('status', $status);
        }
        // Payments waiting for a decision first.
        $query->orderByRaw('case when status = ? then 0 else 1 end', [Payment::STATUS_SUBMITTED]);
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('utr', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('reference', 'like', $like)->orWhere('buyer_name', 'like', $like)->orWhere('buyer_phone', 'like', $like)));
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();
        $rows = $query->latest('id')->skip($offset)->take($length)->get()->map(fn (Payment $p) => $this->row($p))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->find($request, $uid)));
    }

    /** Record section: expected vs paid, UTR, the screenshot, the order. */
    public function summary(Request $request, string $uid): View
    {
        $payment = $this->find($request, $uid)->load(['order.lines', 'method']);

        return view('payment::admin.summary', ['payment' => $payment]);
    }

    public function proof(Request $request, string $uid): StreamedResponse
    {
        $payment = Payment::query()->with('order')->where('uid', $uid)->firstOrFail();
        abort_unless($payment->order && PaymentAccess::canPay($request->user(), $payment->order), 404);
        $disk = Storage::disk(Payment::PROOF_DISK);
        abort_unless($payment->proof_path && $disk->exists($payment->proof_path), 404);

        return $disk->response($payment->proof_path, $payment->proof_name, ['Cache-Control' => 'private, max-age=600']);
    }

    /** The decision form (payment-decision) posts here. */
    public function decide(Request $request, string $uid): JsonResponse
    {
        $payment = $this->find($request, $uid);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verify', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ], ['note.required_if' => 'Say why, so the payer knows what to fix.']);

        try {
            $data['decision'] === 'verify'
                ? $this->payments->verify($payment, (int) $request->user()->id, $data['note'] ?? null)
                : $this->payments->reject($payment, $data['note'], (int) $request->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $data['decision'] === 'verify' ? 'Payment verified; the order is paid.' : 'Payment rejected; the payer can send it again.']);
    }

    /** The "Record a payment" form (payment-mark-paid) posts here. */
    public function markPaid(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:48'],
            'kind' => ['required', Rule::in(PaymentMethod::KINDS)],
            'amount' => ['required', 'numeric', 'min:0'],
            'utr' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = Order::query()->with('organisation')->whereIn('organisation_id', PaymentAccess::organisationIds($request->user()))
            ->where('reference', strtoupper(trim($data['reference'])))->first();
        if (! $order || ! PaymentAccess::canManage($request->user(), $order)) {
            return response()->json(['success' => false, 'message' => 'No order with that reference.', 'errors' => ['reference' => ['No order with that reference in your organisations.']]], 422);
        }
        if (round((float) $data['amount'], 2) < round((float) $order->total, 2) && empty($data['note'])) {
            return response()->json(['success' => false, 'message' => 'Less than the order total.', 'errors' => ['note' => ['The amount is less than the total ('.$order->total.'): add a note why.']]], 422);
        }

        try {
            $this->payments->markPaid($order, $data['kind'], (float) $data['amount'], (int) $request->user()->id, $data['utr'] ?? null, $data['note'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['reference' => [$e->getMessage()]]], 422);
        }

        return response()->json(['success' => true, 'message' => "Payment recorded; {$order->reference} is paid."], 201);
    }

    private function scoped(Request $request): Builder
    {
        $allowed = PaymentAccess::organisationIds($request->user());
        $context = app()->bound(OrganisationContext::class) ? app(OrganisationContext::class)->id($request->user()) : null;
        if ($context && in_array($context, $allowed, true)) {
            $allowed = [$context];
        }

        return Payment::query()->whereIn('organisation_id', $allowed);
    }

    private function find(Request $request, string $uid): Payment
    {
        $payment = Payment::query()->with(['order.organisation', 'organisation:id,name'])->where('uid', $uid)->first();
        abort_unless($payment && $payment->order && PaymentAccess::canManage($request->user(), $payment->order), 404);

        return $payment;
    }

    private function row(Payment $payment): array
    {
        $order = $payment->order;
        $mismatch = $order && round((float) $payment->amount, 2) !== round((float) $order->total, 2);

        return [
            'uid' => $payment->uid,
            'reference' => $order?->reference,
            'buyer' => trim(($order?->buyer_name ?? '').($order?->buyer_phone ? ' · '.$order->buyer_phone : '')),
            'expected' => $order ? $order->currency.' '.number_format((float) $order->total, 2) : null,
            'amount_label' => $payment->currency.' '.number_format((float) $payment->amount, 2).($mismatch ? ' (≠ order)' : ''),
            'utr' => $payment->utr,
            'kind' => $payment->kind === PaymentMethod::KIND_CASH ? 'Cash' : 'UPI',
            'status' => $payment->status,
            'status_label' => $payment->statusLabel(),
            'paid_on' => $payment->paid_on?->format('j M Y'),
            'organisation' => $payment->organisation?->name,
            'submitted_at' => $payment->created_at?->format('Y-m-d H:i'),
            'is_open' => $payment->status === Payment::STATUS_SUBMITTED ? '1' : '0',
        ];
    }
}
