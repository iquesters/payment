<?php

namespace Iquesters\Payment;

use Iquesters\Foundation\System\Providers\BaseServiceProvider;

class PaymentServiceProvider extends BaseServiceProvider
{
    // Routes, views, migrations, the seeder command and the conf are
    // auto-detected by BaseServiceProvider.

    public function boot(): void
    {
        parent::boot();

        // The Payments list's rows (resources/views/records/payment/summary): only with
        // iquesters/user-interface, which renders them.
        $registry = 'Iquesters\UserInterface\Support\RecordComponents';
        if (class_exists($registry)) {
            $this->app->make($registry)->register('payment.summary', 'payment::records.payment.summary', 'summary', 'Payment (order, status badge, amount, UTR)');
        }
    }
}
