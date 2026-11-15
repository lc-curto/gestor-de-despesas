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

        // Durante a revisão, permitir acesso apenas às áreas de manutenção dos dados
        // existentes, para que o utilizador consiga corrigir o que encontrar.
        $dataMaintenanceRoutes = [
            'financial.accounts.*',
            'financial.movements.*',
            'categorias.*',
            'despesas.*',
            'receitas.*',
            'dashboard.movimentos',
            'dashboard.movimentos.data',
        ];

        $isReviewOrMaintenanceRoute = $request->routeIs('financial.review.*')
            || $request->routeIs('logout')
            || $request->routeIs(...$dataMaintenanceRoutes);

        if ($user && $user->financial_review_required && !$isReviewOrMaintenanceRoute) {
            return redirect()->route('financial.review.index');
        }

        return $next($request);
    }
}
