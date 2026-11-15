<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFinancialDataReviewed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->financial_review_required
            && !$request->routeIs('financial.review.*')
            && !$request->routeIs('logout')) {
            return redirect()->route('financial.review.index');
        }

        return $next($request);
    }
}
