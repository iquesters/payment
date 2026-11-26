<?php

namespace Iquesters\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/** How an organisation takes money: UPI to its UPI ID (shown as a QR), cash… */
class PaymentMethod extends Model
{
    public const KIND_UPI = 'upi_manual';

    public const KIND_CASH = 'cash';

    public const KINDS = [self::KIND_UPI, self::KIND_CASH];

    protected $table = 'payment_methods';

    protected $fillable = ['uid', 'organisation_id', 'kind', 'label', 'settings', 'is_active', 'sort', 'created_by', 'updated_by'];

    protected $casts = ['settings' => 'array', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $method) => $method->uid ??= (string) Str::ulid());
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ((array) $this->settings)[$key] ?? $default;
    }

    public static function upiFor(int $organisationId): ?self
    {
        return self::query()->where('organisation_id', $organisationId)->where('kind', self::KIND_UPI)->where('is_active', true)->first();
    }
}
