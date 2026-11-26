<?php

namespace Iquesters\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Iquesters\Commerce\Models\Order;
use Iquesters\Organisation\Models\Organisation;

/**
 * A payment made for an order: its amount, the UPI transaction ID (UTR) and
 * screenshot the payer sent (or a cash payment an admin recorded), and its
 * verification.
 */
class Payment extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const LABELS = [
        self::STATUS_SUBMITTED => 'Waiting for verification',
        self::STATUS_VERIFIED => 'Verified',
        self::STATUS_REJECTED => 'Rejected',
    ];

    public const PROOF_DISK = 'local';

    public const PROOF_DIRECTORY = 'payment/proofs';

    protected $table = 'payments';

    protected $fillable = [
        'uid', 'organisation_id', 'order_id', 'payment_method_id', 'kind', 'amount', 'currency', 'utr', 'paid_on',
        'proof_path', 'proof_name', 'proof_mime', 'proof_size', 'status', 'note', 'reason',
        'submitted_by', 'verified_by', 'verified_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date', 'verified_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->uid ??= (string) Str::ulid();
            $payment->status ??= self::STATUS_SUBMITTED;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->proof_mime, 'image/');
    }
}
