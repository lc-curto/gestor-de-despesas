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
        $filtroTipo = in_array($request->query('tipo'), ['despesa', 'receita'], true)
            ? $request->query('tipo')
            : 'todos';
        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $filtroCategoria = array_key_exists($request->query('categoria', 'all'), $categorias)
            ? $request->query('categoria', 'all')
            : 'all';

        // Os totais representam o mês completo, independentemente da página visível.
        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->sum('amount');

        $query = $this->movimentosQuery(
            $user->id,
            $inicio,
            $fim,
            $filtroTipo,
            $filtroCategoria
        );

        $limit = $this->pageSize($request);
        $totalMovimentos = (clone $query)->count();
        $movimentos = (clone $query)->limit($limit)->get();

        return view('dashboard', compact(
            'inicio',
            'mes',
            'movimentos',
            'categorias',
            'filtroTipo',
            'filtroCategoria',
            'totalDespesas',
            'totalReceitas',
            'totalMovimentos',
            'limit'
        ))->with('moeda', $user->currency);
    }

    /**
     * Devolve uma página global de despesas e receitas.
     *
     * A união é ordenada e paginada no banco de dados. Assim, o limite é
     * aplicado ao conjunto combinado, e não separadamente a cada tabela.
     */
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
        $page = min(100000, max(1, (int) $request->query('page', 1)));
        $offset = ($page - 1) * $limit;

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

        $query = $this->movimentosQuery(
            $user->id,
            $inicio,
            $fim,
            'todos',
            $categoria
        );

        $items = (clone $query)
            ->offset($offset)
            ->limit($limit)
            ->get();

        $total = $totalDespesasFiltradas + $totalReceitasFiltradas;

        // Os indicadores financeiros não dependem dos filtros do histórico.
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

    /**
     * Limita o volume de dados devolvido numa única requisição.
     */
    private function pageSize(Request $request): int
    {
        return min(50, max(1, (int) $request->query('limit', 10)));
    }

    /**
     * Monta uma consulta combinada, mantendo o filtro de proprietário no SQL.
     */
    private function movimentosQuery(
        string $userId,
        Carbon $inicio,
        Carbon $fim,
        string $tipo,
        string $categoria
    ): Builder {
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
