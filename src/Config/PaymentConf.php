<?php

namespace Iquesters\Payment\Config;

use Iquesters\Foundation\Support\BaseConf;
use Iquesters\Payment\Enums\Module;

/** Payment settings (app-wide; each organisation's methods are in payment_methods). */
class PaymentConf extends BaseConf
{
    protected ?string $identifier = Module::PAYMENT;

    /** The UPI transaction ID (UTR) is required with a UPI payment proof. */
    protected bool $require_utr;

    /** A screenshot is required with a UPI payment proof. */
    protected bool $require_proof_file;

    /** Largest proof file (KB). */
    protected int $proof_max_kb;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->require_utr = true;
        $default_values->require_proof_file = true;
        $default_values->proof_max_kb = 5120;
    }

    protected function rules(): array
    {
        return [
            'require_utr' => 'boolean',
            'require_proof_file' => 'boolean',
            'proof_max_kb' => 'integer|between:100,20480',
        ];
    }
}
