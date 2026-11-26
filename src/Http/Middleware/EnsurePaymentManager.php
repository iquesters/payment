<?php

namespace Iquesters\Payment\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Iquesters\Payment\Support\PaymentAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payment admin pages: super-admins and org-admins (of at least one
 * organisation). Which organisations' payments each may touch is checked
 * again in the controller. Auto-registered as `ensure.payment.manager`.
 */
class EnsurePaymentManager
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(PaymentAccess::organisationIds($request->user()) === [], 403);

        return $next($request);
    }
}
