<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureFinancialDataReviewed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $dispatch = function () use ($request, $next, $user): Response {
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

            if ($user && $user->financial_review_required && ! $isReviewOrMaintenanceRoute) {
                return redirect()->route('financial.review.index');
            }

            return $next($request);
        };

        if (! $user || $request->isMethodSafe()) {
            return $dispatch();
        }

        // Todas as escritas HTTP autenticadas (incluindo a confirmação final da revisão)
        // obtêm o mesmo lock por utilizador e mantêm-no até a resposta terminar.
        // Assim uma escrita e a conclusão da revisão não podem passar-se uma pela outra.
        return DB::transaction(function () use ($user, $dispatch): Response {
            $row = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! $row) {
                abort(401);
            }
            $user->setAttribute('financial_review_required', (bool) $row->financial_review_required);

            return $dispatch();
        });
    }
}
