<?php

namespace Iquesters\Payment\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Commerce\Models\Order;
use Iquesters\Organisation\Support\OrganisationAccess;

/**
 * Who verifies payments and records cash: super-admins and the organisation's
 * org-admins. Other modules may allow more people for their own orders (e.g.
 * event: an event admin for the orders of the organisation's events) through
 * PaymentAccess::allow(fn ($user, Order $order): bool).
 * The buyer may pay and send proof for their own order.
 */
class PaymentAccess
{
    /** @var array<int, callable(Authenticatable, Order): bool> */
    private static array $rules = [];

    public static function allow(callable $rule): void
    {
        self::$rules[] = $rule;
    }

    public static function forget(): void
    {
        self::$rules = [];
    }

    public static function canManage(?Authenticatable $user, Order $order): bool
    {
        if (! $user) {
            return false;
        }
        if (OrganisationAccess::canAdminister($user, $order->organisation)) {
            return true;
        }
        foreach (self::$rules as $rule) {
            if ($rule($user, $order)) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] organisations whose payments the user manages (the queue, settings) */
    public static function organisationIds(?Authenticatable $user): array
    {
        return OrganisationAccess::adminOrganisationIds($user);
    }

    /** The buyer of the order (or someone who manages it). */
    public static function canPay(?Authenticatable $user, Order $order): bool
    {
        return $user && ((int) $order->user_id === (int) $user->getAuthIdentifier() || self::canManage($user, $order));
    }
}
