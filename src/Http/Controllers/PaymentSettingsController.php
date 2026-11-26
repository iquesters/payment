<?php

namespace Iquesters\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationAccess;
use Iquesters\Organisation\Support\OrganisationContext;
use Iquesters\Payment\Models\PaymentMethod;
use Iquesters\Payment\Support\PaymentAccess;
use Iquesters\Payment\Support\PaymentForms;
use Iquesters\Payment\Support\UpiLink;

/** Payment settings of an organisation (form schema payment-settings): UPI ID, payee name, cash, refund policy. */
class PaymentSettingsController extends Controller
{
    /** The organisation worked in, else the first one managed. */
    public function index(Request $request): RedirectResponse
    {
        $ids = PaymentAccess::organisationIds($request->user());
        $context = app()->bound(OrganisationContext::class) ? app(OrganisationContext::class)->current($request->user()) : null;
        $organisation = $context && in_array((int) $context->id, $ids, true)
            ? $context
            : Organisation::query()->whereIn('id', $ids)->orderBy('name')->first();
        abort_unless($organisation, 404, 'Create an organisation first.');

        return redirect()->route('payment.settings.edit', $organisation->uid);
    }

    public function edit(Request $request, string $uid): View
    {
        $organisation = $this->organisation($request, $uid);
        $upi = PaymentMethod::query()->where('organisation_id', $organisation->id)->where('kind', PaymentMethod::KIND_UPI)->first();
        $cash = PaymentMethod::query()->where('organisation_id', $organisation->id)->where('kind', PaymentMethod::KIND_CASH)->first();

        return view('payment::settings', [
            'organisation' => $organisation,
            'organisations' => Organisation::query()->whereIn('id', PaymentAccess::organisationIds($request->user()))->orderBy('name')->get(['uid', 'name']),
            'formId' => PaymentForms::SETTINGS,
            'formData' => [
                'uid' => $organisation->uid,
                'upi_enabled' => (bool) $upi?->is_active,
                'upi_id' => $upi?->setting('upi_id'),
                'payee_name' => $upi?->setting('payee_name'),
                'instructions' => $upi?->setting('instructions'),
                'cash_enabled' => (bool) $cash?->is_active,
                'refund_policy' => $organisation->getMetaValue('refund_policy'),
            ],
        ]);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $organisation = $this->organisation($request, $uid);
        $data = $request->validate([
            'upi_enabled' => ['nullable', 'boolean'],
            'upi_id' => ['nullable', 'string', 'max:256', 'required_if:upi_enabled,1,true'],
            'payee_name' => ['nullable', 'string', 'max:100', 'required_if:upi_enabled,1,true'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'cash_enabled' => ['nullable', 'boolean'],
            'refund_policy' => ['nullable', 'string', 'max:5000'],
        ]);
        if (! empty($data['upi_id']) && ! UpiLink::isValidId($data['upi_id'])) {
            return response()->json(['success' => false, 'message' => 'Check the UPI ID.', 'errors' => ['upi_id' => ['A UPI ID looks like name@bank (e.g. rahul.sen@okaxis).']]], 422);
        }

        $userId = (int) $request->user()->id;
        DB::transaction(function () use ($organisation, $data, $userId) {
            PaymentMethod::query()->updateOrCreate(
                ['organisation_id' => $organisation->id, 'kind' => PaymentMethod::KIND_UPI],
                ['label' => 'UPI', 'is_active' => (bool) ($data['upi_enabled'] ?? false), 'updated_by' => $userId, 'settings' => [
                    'upi_id' => trim((string) ($data['upi_id'] ?? '')),
                    'payee_name' => trim((string) ($data['payee_name'] ?? '')),
                    'instructions' => trim((string) ($data['instructions'] ?? '')),
                ]]
            );
            PaymentMethod::query()->updateOrCreate(
                ['organisation_id' => $organisation->id, 'kind' => PaymentMethod::KIND_CASH],
                ['label' => 'Cash', 'is_active' => (bool) ($data['cash_enabled'] ?? false), 'updated_by' => $userId]
            );
            $organisation->setMetaValue('refund_policy', trim((string) ($data['refund_policy'] ?? '')));
        });

        return response()->json(['success' => true, 'message' => 'Payment settings saved.']);
    }

    private function organisation(Request $request, string $uid): Organisation
    {
        $organisation = Organisation::query()->where('uid', $uid)->firstOrFail();
        abort_unless(OrganisationAccess::canAdminister($request->user(), $organisation), 404);

        return $organisation;
    }
}
