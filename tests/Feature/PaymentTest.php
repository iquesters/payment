<?php

namespace Iquesters\Payment\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Iquesters\Commerce\Events\OrderPaid;
use Iquesters\Commerce\Models\Order;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Payment\Models\Payment;
use Iquesters\Payment\Models\PaymentMethod;
use Iquesters\Payment\Support\PaymentAccess;
use Iquesters\Payment\Support\UpiLink;
use Iquesters\Payment\Tests\TestCase;

/** The pay page, proof, verification, desk payments, settings and who may do what. */
class PaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upi(Organisation $organisation): PaymentMethod
    {
        return PaymentMethod::create([
            'organisation_id' => $organisation->id,
            'kind' => PaymentMethod::KIND_UPI,
            'label' => 'UPI',
            'is_active' => true,
            'settings' => ['upi_id' => 'ttv.alumni@okaxis', 'payee_name' => 'TTV Alumni', 'instructions' => 'Pay once.'],
        ]);
    }

    private function sendProof(Order $order, $user, string $utr = '412345678901')
    {
        return $this->actingAs($user)->post('/pay/'.$order->uid, [
            'utr' => $utr,
            'proof' => UploadedFile::fake()->image('paid.png'),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_upi_link_has_the_exact_amount_and_the_reference(): void
    {
        $organisation = $this->makeOrganisation();
        $order = $this->makeOrder($organisation, null, 1200);

        $this->assertSame('upi://pay?pa=ttv.alumni%40okaxis&pn=TTV%20Alumni&am=1200.00&cu=INR&tn=TTV-MEET-0001', UpiLink::for($this->upi($organisation), $order));
        $this->assertTrue(UpiLink::isValidId('rahul.sen@okaxis'));
        $this->assertFalse(UpiLink::isValidId('rahul sen'));
    }

    public function test_the_buyer_sees_the_pay_page_and_others_do_not(): void
    {
        $organisation = $this->makeOrganisation();
        $this->upi($organisation);
        $buyer = $this->memberOf($organisation);
        $order = $this->makeOrder($organisation, $buyer);

        $this->actingAs($buyer)->get('/pay/'.$order->uid.'?return=/events')
            ->assertOk()->assertSee('TTV-MEET-0001')->assertSee('ttv.alumni@okaxis')->assertSee('upi-qr', false);
        $this->actingAs($this->memberOf($organisation))->get('/pay/'.$order->uid)->assertNotFound();
    }

    public function test_the_same_item_twice_shows_once_with_its_count(): void
    {
        $organisation = $this->makeOrganisation();
        $this->upi($organisation);
        $buyer = $this->memberOf($organisation);
        $pass = $this->makeProduct($organisation, ['name' => 'Pass', 'price' => 600]);
        $child = $this->makeProduct($organisation, ['name' => 'Child', 'price' => 0]);
        // One line per person (as an event registration makes them).
        $order = app(\Iquesters\Commerce\Services\OrderService::class)->create($organisation, ['user_id' => $buyer->id, 'name' => 'Rahul'], [
            ['product' => $pass, 'meta' => ['person' => 'Rahul']],
            ['product' => $pass, 'meta' => ['person' => 'Priya', 'member_details' => ['summary' => 'ISC 2005']]],
            ['product' => $child, 'meta' => ['person' => 'Tutun']],
            // A bundle's second line for the same person (her membership) carries the same details.
            ['product' => $this->makeProduct($organisation, ['name' => 'Membership 2027', 'price' => 0]), 'meta' => ['person' => 'Priya', 'member_details' => ['summary' => 'ISC 2005']]],
        ], ['prefix' => 'TTV-MEET']);

        $this->actingAs($buyer)->get('/pay/'.$order->uid)->assertOk()
            ->assertSee('2 × Pass')->assertDontSee('1 × Pass')->assertSee('1 × Child')
            ->assertSee('INR 1,200.00');

        // The admin's summary of the payment too.
        $this->sendProof($order, $buyer)->assertOk();
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $summary = $this->actingAs($this->memberOf($organisation, 'admin'))->get('/payment/payments/'.$payment->uid.'/summary');
        // Each member once (not once per line of their bundle).
        $this->assertSame(1, substr_count($summary->getContent(), 'ISC 2005'));
        $summary->assertOk()
            ->assertSee('2 × Pass')->assertDontSee('1 × Pass')->assertSee('1 × Child')
            // A member's details given at registration (e.g. their batch).
            ->assertSee('Members')->assertSee('Priya')->assertSee('ISC 2005');
    }

    public function test_sending_proof_puts_the_order_under_review_and_a_used_utr_is_refused(): void
    {
        $organisation = $this->makeOrganisation();
        $this->upi($organisation);
        $buyer = $this->memberOf($organisation);
        $order = $this->makeOrder($organisation, $buyer);

        $this->sendProof($order, $buyer, '4123 4567 8901')->assertOk()->assertJson(['success' => true]);

        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame(['412345678901', '600.00', Payment::STATUS_SUBMITTED], [$payment->utr, $payment->amount, $payment->status]);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertSame(Order::STATUS_UNDER_REVIEW, $order->fresh()->status);

        // Under review: no second proof; another order can't reuse the UTR.
        $this->sendProof($order, $buyer, '999999999999')->assertStatus(422);
        $other = $this->makeOrder($organisation, $buyer);
        $this->sendProof($other, $buyer)->assertStatus(422)->assertJsonPath('errors.utr.0', 'This UTR was already used for another payment. Check the number, or contact the organisers.');

        // The UTR and screenshot are required.
        $this->actingAs($buyer)->post('/pay/'.$other->uid, [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['utr', 'proof']);
    }

    public function test_an_org_admin_verifies_and_the_order_is_paid(): void
    {
        Event::fake([OrderPaid::class]);
        $organisation = $this->makeOrganisation();
        $this->upi($organisation);
        $buyer = $this->memberOf($organisation);
        $admin = $this->memberOf($organisation, 'admin');
        $order = $this->makeOrder($organisation, $buyer);
        $this->sendProof($order, $buyer);
        $payment = Payment::query()->sole();

        $rows = $this->actingAs($admin)->getJson('/payment/payments/data')->assertOk()->json('response_schema.data');
        $this->assertSame([['TTV-MEET-0001', '1', 'Waiting for verification']], array_map(fn ($r) => [$r['reference'], $r['is_open'], $r['status_label']], $rows));
        $this->actingAs($admin)->get('/payment/payments/'.$payment->uid.'/summary')->assertOk()->assertSee('412345678901');
        $this->actingAs($admin)->get('/payment/payments/'.$payment->uid.'/proof')->assertOk();

        $this->actingAs($admin)->postJson('/payment/payments/'.$payment->uid.'/decision', ['decision' => 'verify'])->assertOk();

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Event::assertDispatched(OrderPaid::class);
        $this->actingAs($admin)->postJson('/payment/payments/'.$payment->uid.'/decision', ['decision' => 'reject', 'note' => 'x'])->assertStatus(422);
    }

    public function test_a_rejected_payment_can_be_sent_again_with_the_same_utr(): void
    {
        $organisation = $this->makeOrganisation();
        $this->upi($organisation);
        $buyer = $this->memberOf($organisation);
        $admin = $this->memberOf($organisation, 'admin');
        $order = $this->makeOrder($organisation, $buyer);
        $this->sendProof($order, $buyer);
        $payment = Payment::query()->sole();

        // A reason is required.
        $this->actingAs($admin)->postJson('/payment/payments/'.$payment->uid.'/decision', ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson('/payment/payments/'.$payment->uid.'/decision', ['decision' => 'reject', 'note' => 'Not in the statement yet'])->assertOk();

        $this->assertSame(Order::STATUS_REJECTED, $order->fresh()->status);
        $this->actingAs($buyer)->get('/pay/'.$order->uid)->assertOk()->assertSee('Not in the statement yet');
        $this->sendProof($order, $buyer)->assertOk();
        $this->assertSame(Order::STATUS_UNDER_REVIEW, $order->fresh()->status);

        // The list's row cards: waiting first, a coloured badge; the resend marked, the rejected one with its reason.
        $rows = collect($this->actingAs($admin)->getJson('/payment/payments/data')->assertOk()->json('response_schema.data'));
        $this->assertSame([['warning', '2nd attempt', null], ['danger', null, 'Not in the statement yet']], $rows->map(fn ($r) => [$r['status_tone'], $r['attempt_label'], $r['reason']])->all());
        // The new one's panel lists the earlier, rejected payment.
        $this->actingAs($admin)->get('/payment/payments/'.$rows[0]['uid'].'/summary')->assertOk()
            ->assertSee('Other payments for TTV-MEET-0001')->assertSee('Rejected: Not in the statement yet');
    }

    public function test_an_admin_records_cash_by_reference(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $order = $this->makeOrder($organisation);

        // Less than the total needs a note.
        $this->actingAs($admin)->postJson('/payment/payments/mark-paid', ['reference' => 'ttv-meet-0001', 'kind' => 'cash', 'amount' => 500])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson('/payment/payments/mark-paid', ['reference' => 'TTV-MEET-0404', 'kind' => 'cash', 'amount' => 600])
            ->assertStatus(422)->assertJsonValidationErrors('reference');

        $this->actingAs($admin)->postJson('/payment/payments/mark-paid', ['reference' => 'ttv-meet-0001', 'kind' => 'cash', 'amount' => 600])->assertCreated();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame([PaymentMethod::KIND_CASH, Payment::STATUS_VERIFIED], [Payment::query()->sole()->kind, Payment::query()->sole()->status]);
    }

    public function test_admins_only_see_their_own_organisations(): void
    {
        $ttv = $this->makeOrganisation();
        $other = $this->makeOrganisation('Other school');
        $this->upi($ttv);
        $buyer = $this->memberOf($ttv);
        $order = $this->makeOrder($ttv, $buyer);
        $this->sendProof($order, $buyer);
        $payment = Payment::query()->sole();

        $otherAdmin = $this->memberOf($other, 'admin');
        $this->assertSame([], $this->actingAs($otherAdmin)->getJson('/payment/payments/data')->assertOk()->json('response_schema.data'));
        $this->actingAs($otherAdmin)->postJson('/payment/payments/'.$payment->uid.'/decision', ['decision' => 'verify'])->assertNotFound();
        $this->actingAs($otherAdmin)->get('/payment/payments/'.$payment->uid.'/proof')->assertNotFound();
        $this->actingAs($otherAdmin)->postJson('/payment/payments/mark-paid', ['reference' => 'TTV-MEET-0001', 'kind' => 'cash', 'amount' => 600])->assertStatus(422);

        // Members don't reach the admin pages at all.
        $this->actingAs($buyer)->getJson('/payment/payments/data')->assertForbidden();

        // Another module may let more people manage (e.g. an event admin).
        $eventAdmin = $this->memberOf($ttv);
        PaymentAccess::allow(fn ($user, Order $o) => $user->is($eventAdmin) && $o->is($order));
        $this->assertTrue(PaymentAccess::canManage($eventAdmin, $order));
    }

    public function test_an_org_admin_sets_up_upi_and_the_refund_policy(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');

        $this->actingAs($admin)->get('/payment/settings')->assertRedirect('/payment/settings/'.$organisation->uid);
        $this->actingAs($admin)->get('/payment/settings/'.$organisation->uid)->assertOk();

        $this->actingAs($admin)->putJson('/payment/settings/'.$organisation->uid, ['upi_enabled' => true, 'upi_id' => 'not a upi id', 'payee_name' => 'TTV'])
            ->assertStatus(422)->assertJsonValidationErrors('upi_id');
        $this->actingAs($admin)->putJson('/payment/settings/'.$organisation->uid, [
            'upi_enabled' => true, 'upi_id' => 'ttv.alumni@okaxis', 'payee_name' => 'TTV Alumni', 'cash_enabled' => true, 'refund_policy' => 'No refunds after 25 Dec.',
        ])->assertOk();

        $upi = PaymentMethod::upiFor($organisation->id);
        $this->assertSame(['ttv.alumni@okaxis', 'TTV Alumni'], [$upi->setting('upi_id'), $upi->setting('payee_name')]);
        $this->assertSame('No refunds after 25 Dec.', $organisation->fresh()->getMetaValue('refund_policy'));

        $this->actingAs($this->memberOf($this->makeOrganisation('Other'), 'admin'))->get('/payment/settings/'.$organisation->uid)->assertNotFound();
    }
}
