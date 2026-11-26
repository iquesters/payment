<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Payment\Http\Controllers\PayController;
use Iquesters\Payment\Http\Controllers\PaymentAdminController;
use Iquesters\Payment\Http\Controllers\PaymentSettingsController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(['web', 'auth'])->group(function () use ($ulid) {
    // The buyer: the pay page of an order (UPI QR) and the proof it posts (form payment-proof).
    Route::get('pay/{uid}', [PayController::class, 'show'])->where('uid', $ulid)->name('payment.pay');
    Route::post('pay/{uid}', [PayController::class, 'store'])->where('uid', $ulid)->name('payment.pay.store');

    // The screenshot: the buyer and the admins of the order (checked in the controller).
    Route::get('payment/payments/{uid}/proof', [PaymentAdminController::class, 'proof'])->where('uid', $ulid)->name('payment.payments.proof');

    // Super-admins and org-admins; the controllers limit them to their organisations.
    Route::middleware('ensure.payment.manager')->prefix('payment')->name('payment.')->group(function () use ($ulid) {
        // The generic table payment-table: rows, records, the summary section.
        Route::get('payments/data', [PaymentAdminController::class, 'data'])->name('payments.data');
        Route::get('payments/data/{uid}', [PaymentAdminController::class, 'record'])->where('uid', $ulid)->name('payments.record');
        Route::get('payments/{uid}/summary', [PaymentAdminController::class, 'summary'])->where('uid', $ulid)->name('payments.summary');
        Route::post('payments/{uid}/decision', [PaymentAdminController::class, 'decide'])->where('uid', $ulid)->name('payments.decide');
        Route::post('payments/mark-paid', [PaymentAdminController::class, 'markPaid'])->name('payments.mark-paid');

        // How each organisation takes money (form payment-settings).
        Route::get('settings', [PaymentSettingsController::class, 'index'])->name('settings.index');
        Route::get('settings/{uid}', [PaymentSettingsController::class, 'edit'])->where('uid', '[A-Za-z0-9-]+')->name('settings.edit');
        Route::put('settings/{uid}', [PaymentSettingsController::class, 'update'])->where('uid', '[A-Za-z0-9-]+')->name('settings.update');
    });
});
