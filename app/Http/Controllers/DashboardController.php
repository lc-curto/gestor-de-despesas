<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $accounts = $user->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash', 'credit_card'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->orderBy('name')
            ->get();
        [$inicio, $mes] = $this->periodo($request->query('mes'));
        $fim = $inicio->copy()->endOfMonth();

        $filtroTipo = in_array($request->query('tipo'), ['despesa', 'receita'], true)
            ? $request->query('tipo') : 'todos';

        $categoriasDespesa = $user->categorias()->where('type', 'despesa')->orderBy('name')->pluck('name', 'id')->all();
        $categoriasReceita = $user->categorias()->where('type', 'receita')->orderBy('name')->pluck('name', 'id')->all();
        $categorias = $categoriasDespesa + $categoriasReceita;
        $requestedCategory = (string) $request->query('categoria', 'all');
        $filtroCategoria = $requestedCategory === 'all' || array_key_exists($requestedCategory, $categorias)
            ? $requestedCategory : 'all';

        $totais = $user->movimentos()
            ->where('status', 'settled')
            ->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS despesas")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS receitas")
            ->first();
        $totalDespesas = (float) $totais->despesas;
        $totalReceitas = (float) $totais->receitas;

        // A primeira página carrega os dois tipos de movimento. O filtro de tipo é
        // aplicado no cliente sem uma segunda requisição ao abrir o Dashboard.
        $despesasFiltradas = $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()]);
        $receitasFiltradas = $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()]);

        if ($filtroCategoria !== 'all') {
            $despesasFiltradas->where('category_id', $filtroCategoria);
            $receitasFiltradas->where('category_id', $filtroCategoria);
        }

        $totalDespesasFiltradas = (clone $despesasFiltradas)->count();
        $totalReceitasFiltradas = (clone $receitasFiltradas)->count();
        $totalMovimentos = $totalDespesasFiltradas + $totalReceitasFiltradas;

        $query = $this->movimentosQuery($user->id, $inicio, $fim, 'todos', $filtroCategoria);
        $limit = $this->pageSize($request);
        $movimentos = (clone $query)->limit($limit)->get();
        $movimentosVisiveis = $movimentos->filter(
            fn ($movimento) => $filtroTipo === 'todos' || $movimento->tipo === $filtroTipo
        )->values();

        $movimentosIniciais = $movimentos->map(function ($item) {
            return [
                'id' => $item->id,
                'tipo' => $item->tipo,
                'description' => $item->description,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'category' => $item->category,
                'category_label' => $item->category_label,
                'category_icon' => $item->category_icon,
                'category_color' => $item->category_color,
                'data_movimento' => $item->data_movimento,
                'account_id' => $item->account_id,
                'movement_status' => $item->movement_status,
                'is_historical' => (bool) ($item->is_historical ?? false),
                'requires_account_assignment' => $item->requires_account_assignment,
            ];
        })->values();

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'movimentosVisiveis', 'movimentosIniciais', 'categorias', 'categoriasDespesa',
            'categoriasReceita', 'filtroTipo', 'filtroCategoria',
            'totalDespesas', 'totalReceitas', 'totalMovimentos', 'totalDespesasFiltradas', 'totalReceitasFiltradas', 'limit'
        ))->with('moeda', $user->currency)->with('accounts', $accounts);
    }

    public function movimentos(Request $request)
    {
        $user = $request->user();
        [$inicio] = $this->periodo($request->query('mes'));
        $fim = $inicio->copy()->endOfMonth();

        $categorias = $user->categorias()->pluck('id')->all();
        $categoria = (string) $request->query('categoria', 'all');
        if ($categoria !== 'all' && !in_array($categoria, $categorias, true)) {
            $categoria = 'all';
        }

        $limit = $this->pageSize($request);
        $page = min(100000, max(1, (int) $request->query('page', 1)));
        $offset = ($page - 1) * $limit;

        $despesasQuery = $user->despesas()->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()]);
        $receitasQuery = $user->receitas()->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()]);
        if ($categoria !== 'all') {
            $despesasQuery->where('category_id', $categoria);
            $receitasQuery->where('category_id', $categoria);
        }

        $totalDespesasFiltradas = (clone $despesasQuery)->count();
        $totalReceitasFiltradas = (clone $receitasQuery)->count();

        $query = $this->movimentosQuery($user->id, $inicio, $fim, 'todos', $categoria);
        $items = (clone $query)->offset($offset)->limit($limit)->get();
        $total = $totalDespesasFiltradas + $totalReceitasFiltradas;

        $totais = $user->movimentos()
            ->where('status', 'settled')
            ->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS despesas")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS receitas")
            ->first();
        $totalDespesas = (float) $totais->despesas;
        $totalReceitas = (float) $totais->receitas;

        return response()->json([
            'data' => $items,
            'page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_despesas_filtradas' => $totalDespesasFiltradas,
            'total_receitas_filtradas' => $totalReceitasFiltradas,
            'has_more' => ($page * $limit) < $total,
            'total_despesas' => $totalDespesas,
            'total_receitas' => $totalReceitas,
            'moeda' => $user->currency,
            'mes' => $inicio->format('Y-m'),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function periodo(?string $mes): array
    {
        try {
            $inicio = Carbon::createFromFormat('!Y-m', (string) $mes)->startOfMonth();
            if ($inicio->format('Y-m') !== $mes) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            $inicio = now()->startOfMonth();
        }

        return [$inicio, $inicio->format('Y-m')];
    }

    private function pageSize(Request $request): int
    {
        return min(50, max(1, (int) $request->query('limit', 10)));
    }

    private function movimentosQuery(
        string $userId,
        Carbon $inicio,
        Carbon $fim,
        string $tipo,
        string $categoria
    ): Builder {
        $despesas = DB::table('despesas')
            ->join('categorias', 'categorias.id', '=', 'despesas.category_id')
            ->leftJoin('movimentos as financial_movements', function ($join) use ($userId) {
                $join->on('financial_movements.legacy_id', '=', 'despesas.id')
                    ->where('financial_movements.legacy_type', '=', 'despesa')
                    ->where('financial_movements.user_id', '=', $userId);
            })
            ->where('despesas.user_id', $userId)
            ->whereBetween('despesas.expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("despesas.id, 'despesa' AS tipo, despesas.description, despesas.amount, despesas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, despesas.expense_date AS data_movimento, financial_movements.source_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical");

        $receitas = DB::table('receitas')
            ->join('categorias', 'categorias.id', '=', 'receitas.category_id')
            ->leftJoin('movimentos as financial_movements', function ($join) use ($userId) {
                $join->on('financial_movements.legacy_id', '=', 'receitas.id')
                    ->where('financial_movements.legacy_type', '=', 'receita')
                    ->where('financial_movements.user_id', '=', $userId);
            })
            ->where('receitas.user_id', $userId)
            ->whereBetween('receitas.income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("receitas.id, 'receita' AS tipo, receitas.description, receitas.amount, receitas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, receitas.income_date AS data_movimento, financial_movements.destination_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical");

        if ($tipo === 'despesa') {
            $query = $despesas;
        } elseif ($tipo === 'receita') {
            $query = $receitas;
        } else {
            $query = $despesas->unionAll($receitas);
        }

        $wrapped = DB::query()->fromSub($query, 'movimentos');
        if ($categoria !== 'all') {
            $wrapped->where('category', $categoria);
        }

        return $wrapped->orderByDesc('data_movimento')->orderBy('id');
    }
}
