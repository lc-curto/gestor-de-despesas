<?php

namespace App\Http\Controllers;

use App\Support\Categorias;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        [$inicio, $mes] = $this->periodo($request->query('mes'));
        $fim = $inicio->copy()->endOfMonth();
        $filtroTipo = in_array($request->query('tipo'), ['despesa', 'receita'], true) ? $request->query('tipo') : 'todos';
        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $filtroCategoria = array_key_exists($request->query('categoria', 'all'), $categorias)
            ? $request->query('categoria', 'todas')
            : 'all';

        // A versão original soma os montantes do período, independentemente da paginação.
        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');

        $query = $this->movimentosQuery($user->id, $inicio, $fim, $filtroTipo, $filtroCategoria);
        $limit = $this->pageSize($request);
        $totalMovimentos = (clone $query)->count();
        $movimentos = (clone $query)->limit($limit)->get();

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'categorias', 'filtroTipo',
            'filtroCategoria', 'totalDespesas', 'totalReceitas',
            'totalMovimentos', 'limit'
        ))->with('moeda', $user->currency);
    }

    public function movimentos(Request $request)
    {
        $user = $request->user();
        [$inicio] = $this->periodo($request->query('mes'));
        $fim = $inicio->copy()->endOfMonth();
        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $categoria = $request->query('categoria', 'all');
        if ($categoria !== 'all' && !array_key_exists($categoria, $categorias)) {
            $categoria = 'all';
        }

        $limit = $this->pageSize($request);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $limit;

        // A aplicação original carrega uma página de cada tipo e só depois combina os resultados.
        $despesasQuery = $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()]);
        $receitasQuery = $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()]);

        if ($categoria !== 'all') {
            $despesasQuery->where('category', $categoria);
            $receitasQuery->where('category', $categoria);
        }

        $totalDespesasFiltradas = (clone $despesasQuery)->count();
        $totalReceitasFiltradas = (clone $receitasQuery)->count();

        $despesas = (clone $despesasQuery)
            ->orderByDesc('expense_date')->orderBy('id')
            ->offset($offset)->limit($limit)
            ->get()
            ->map(fn ($item) => (object) [
                'id' => $item->id,
                'tipo' => 'despesa',
                'description' => $item->description,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'category' => $item->category,
                'data_movimento' => $item->expense_date,
            ]);

        $receitas = (clone $receitasQuery)
            ->orderByDesc('income_date')->orderBy('id')
            ->offset($offset)->limit($limit)
            ->get()
            ->map(fn ($item) => (object) [
                'id' => $item->id,
                'tipo' => 'receita',
                'description' => $item->description,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'category' => $item->category,
                'data_movimento' => $item->income_date,
            ]);

        $items = $despesas->concat($receitas)->sort(function ($a, $b) {
            $dateOrder = strcmp((string) $b->data_movimento, (string) $a->data_movimento);
            return $dateOrder !== 0 ? $dateOrder : strcmp((string) $a->id, (string) $b->id);
        })->values();

        // Os indicadores são do mês inteiro e não são afetados pelo filtro do histórico.
        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');

        return response()->json([
            'data' => $items,
            'page' => $page,
            'per_page' => $limit,
            'total' => $totalDespesasFiltradas + $totalReceitasFiltradas,
            'total_despesas_filtradas' => $totalDespesasFiltradas,
            'total_receitas_filtradas' => $totalReceitasFiltradas,
            'has_more' => $page * $limit < ($totalDespesasFiltradas + $totalReceitasFiltradas),
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

    private function movimentosQuery(string $userId, Carbon $inicio, Carbon $fim, string $tipo, string $categoria): Builder
    {
        $despesas = DB::table('despesas')
            ->where('user_id', $userId)
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("id, 'despesa' AS tipo, description, amount, currency, category, expense_date AS data_movimento");
        $receitas = DB::table('receitas')
            ->where('user_id', $userId)
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("id, 'receita' AS tipo, description, amount, currency, category, income_date AS data_movimento");

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
