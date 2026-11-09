<?php

namespace App\Http\Controllers;

use App\Support\Categorias;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $mes = $request->query('mes', now()->format('Y-m'));

        try {
            $inicio = Carbon::createFromFormat('!Y-m', $mes)->startOfMonth();
        } catch (\Throwable) {
            $inicio = now()->startOfMonth();
        }

        if ($inicio->format('Y-m') !== $mes) {
            $inicio = now()->startOfMonth();
            $mes = $inicio->format('Y-m');
        }

        $fim = $inicio->copy()->endOfMonth();
        $inicioSql = $inicio->toDateString();
        $fimSql = $fim->toDateString();

        $despesasQuery = $user->despesas()->whereBetween('expense_date', [$inicioSql, $fimSql]);
        $receitasQuery = $user->receitas()->whereBetween('income_date', [$inicioSql, $fimSql]);

        $despesasPorMoeda = (clone $despesasQuery)
            ->selectRaw('currency, SUM(amount) AS total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $receitasPorMoeda = (clone $receitasQuery)
            ->selectRaw('currency, SUM(amount) AS total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $moeda = $user->currency;
        $totalDespesas = (float) ($despesasPorMoeda[$moeda] ?? 0);
        $totalReceitas = (float) ($receitasPorMoeda[$moeda] ?? 0);

        $movimentos = $despesasQuery->get()->map(function ($movimento) {
            $movimento->tipo = 'despesa';
            $movimento->data_movimento = $movimento->expense_date;
            return $movimento;
        })->concat($receitasQuery->get()->map(function ($movimento) {
            $movimento->tipo = 'receita';
            $movimento->data_movimento = $movimento->income_date;
            return $movimento;
        }))->sortByDesc(fn ($movimento) => $movimento->data_movimento->timestamp)
            ->values();

        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $filtroTipo = $request->query('tipo', 'todos');
        $filtroCategoria = $request->query('categoria', 'todas');

        if (in_array($filtroTipo, ['despesa', 'receita'], true)) {
            $movimentos = $movimentos->where('tipo', $filtroTipo)->values();
        }

        if ($filtroCategoria !== 'todas' && array_key_exists($filtroCategoria, $categorias)) {
            $movimentos = $movimentos->where('category', $filtroCategoria)->values();
        }

        $movimentos = $movimentos->take(30);

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'categorias', 'filtroTipo',
            'filtroCategoria', 'moeda', 'totalDespesas', 'totalReceitas',
            'despesasPorMoeda', 'receitasPorMoeda'
        ));
    }
}
