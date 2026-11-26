<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an organisation takes money (payment methods: UPI to a UPI ID, cash;
 * gateways later) and the payments made for commerce orders, with their proof
 * (UTR, screenshot) and who verified them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('organisation_id');
                // upi_manual | cash (later: gateway)
                $table->string('kind', 24);
                $table->string('label')->nullable();
                // upi_manual: upi_id, payee_name, instructions
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['organisation_id', 'kind']);
                $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('organisation_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('payment_method_id')->nullable();
                // upi_manual | cash | other
                $table->string('kind', 24);
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('INR');
                // The UPI transaction ID (UTR) or another reference; one live payment per UTR per organisation.
                $table->string('utr', 64)->nullable();
                $table->date('paid_on')->nullable();
                // The screenshot (private disk).
                $table->string('proof_path')->nullable();
                $table->string('proof_name')->nullable();
                $table->string('proof_mime', 100)->nullable();
                $table->unsignedInteger('proof_size')->nullable();
                // submitted | verified | rejected
                $table->string('status', 16)->default('submitted');
                $table->text('note')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();

                // Not unique: a rejected payment's UTR may be sent again (PaymentService checks the rest).
                $table->index(['organisation_id', 'utr']);
                $table->index(['organisation_id', 'status']);
                $table->index('order_id');
                $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_methods');
    }
};
