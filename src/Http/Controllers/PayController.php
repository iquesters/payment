<?php

namespace Iquesters\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Commerce\Models\Order;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Payment\Enums\Module;
use Iquesters\Payment\Models\Payment;
use Iquesters\Payment\Models\PaymentMethod;
use Iquesters\Payment\Services\PaymentService;
use Iquesters\Payment\Support\PaymentAccess;
use Iquesters\Payment\Support\PaymentForms;
use Iquesters\Payment\Support\UpiLink;

/**
 * The pay page of an order (/pay/{order uid}): the amount, the UPI QR with the
 * exact amount and the reference, then the UTR and screenshot. ?return= (a
 * path on this site) is where "Done" goes back to (e.g. the event page).
 */
class PayController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function show(Request $request, string $uid): View
    {
        $order = $this->order($request, $uid);
        $method = PaymentMethod::upiFor($order->organisation_id);

        return view('payment::pay', [
            'order' => $order->load(['lines', 'organisation']),
            'method' => $method,
            'upiLink' => $method && $method->setting('upi_id') ? UpiLink::for($method, $order) : null,
            'payments' => Payment::query()->where('order_id', $order->id)->latest('id')->get(),
            'formId' => PaymentForms::PROOF,
            'refundPolicy' => (string) $order->organisation?->getMetaValue('refund_policy'),
            'returnUrl' => $this->returnUrl($request),
        ]);
    }

    public function store(Request $request, string $uid): JsonResponse
    {
        $order = $this->order($request, $uid);
        $conf = ConfProvider::from(Module::PAYMENT);
        $request->validate([
            'utr' => [(bool) $conf->require_utr ? 'required' : 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 -]{6,32}$/'],
            'proof' => [(bool) $conf->require_proof_file ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) $conf->proof_max_kb],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['utr.regex' => 'Enter the transaction ID as shown in your UPI app (letters and numbers).']);

        try {
            $this->payments->submitProof($order, (int) $request->user()->id, (string) $request->input('utr'), $request->file('proof'), $request->input('paid_on'));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['utr' => [$e->getMessage()]]], 422);
        }

        // Back to the pay page as it was opened (keeping its ?return=).
        $page = url('/pay/'.$order->uid);
        $previous = url()->previous();
        $back = str_starts_with($previous, $page) ? $previous : $page;
        session()->flash('success', 'Thank you. Your payment details were sent; the organisers will verify them.');

        return response()->json(['success' => true, 'message' => 'Payment details sent.', 'redirect_url' => $back]);
    }

    private function order(Request $request, string $uid): Order
    {
        $order = Order::query()->with('organisation')->where('uid', $uid)->first();
        abort_unless($order && PaymentAccess::canPay($request->user(), $order), 404);

        return $order;
    }

    /** A path on this site only (no other hosts). */
    private function returnUrl(Request $request): ?string
    {
        $return = (string) $request->query('return', $request->input('return', ''));

        return str_starts_with($return, '/') && ! str_starts_with($return, '//') ? $return : null;
    }
}
