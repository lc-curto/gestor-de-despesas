<?php

namespace App\\Http\\Controllers;

use Illuminate\\Database\\Query\\Builder;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Facades\\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
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

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');

        $query = $this->movimentosQuery($user->id, $inicio, $fim, $filtroTipo, $filtroCategoria);
        $limit = $this->pageSize($request);
        $totalMovimentos = (clone $query)->count();
        $movimentos = (clone $query)->limit($limit)->get();

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'categorias', 'categoriasDespesa',
            'categoriasReceita', 'filtroTipo', 'filtroCategoria',
            'totalDespesas', 'totalReceitas', 'totalMovimentos', 'limit'
        ))->with('moeda', $user->currency);
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

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');

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
                throw new \\InvalidArgumentException();
            }
        } catch (\\Throwable) {
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
            ->where('despesas.user_id', $userId)
            ->whereBetween('despesas.expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("despesas.id, 'despesa' AS tipo, despesas.description, despesas.amount, despesas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, despesas.expense_date AS data_movimento");

        $receitas = DB::table('receitas')
            ->join('categorias', 'categorias.id', '=', 'receitas.category_id')
            ->where('receitas.user_id', $userId)
            ->whereBetween('receitas.income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("receitas.id, 'receita' AS tipo, receitas.description, receitas.amount, receitas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, receitas.income_date AS data_movimento");

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
