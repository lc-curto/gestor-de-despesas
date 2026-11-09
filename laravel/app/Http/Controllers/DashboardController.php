<?php

namespace App\\Http\\Controllers;

use App\\Support\\Categorias;
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
        $filtroTipo = in_array($request->query('tipo'), ['despesa', 'receita'], true) ? $request->query('tipo') : 'todos';
        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $filtroCategoria = array_key_exists($request->query('categoria', 'todas'), $categorias)
            ? $request->query('categoria', 'todas')
            : 'todas';

        $despesasPorMoeda = $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw('currency, SUM(amount) AS total')->groupBy('currency')->pluck('total', 'currency');
        $receitasPorMoeda = $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw('currency, SUM(amount) AS total')->groupBy('currency')->pluck('total', 'currency');

        $moeda = $user->currency;
        $totalDespesas = (float) ($despesasPorMoeda[$moeda] ?? 0);
        $totalReceitas = (float) ($receitasPorMoeda[$moeda] ?? 0);

        $query = $this->movimentosQuery($user->id, $inicio, $fim, $filtroTipo, $filtroCategoria);
        $limit = $this->pageSize($request);
        $totalMovimentos = (clone $query)->count();
        $movimentos = (clone $query)->offset(0)->limit($limit)->get();

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'categorias', 'filtroTipo',
            'filtroCategoria', 'moeda', 'totalDespesas', 'totalReceitas',
            'despesasPorMoeda', 'receitasPorMoeda', 'totalMovimentos', 'limit'
        ));
    }

    public function movimentos(Request $request)
    {
        [$inicio] = $this->periodo($request->query('mes'));
        $categorias = Categorias::DESPESAS + Categorias::RECEITAS;
        $tipo = in_array($request->query('tipo'), ['despesa', 'receita'], true) ? $request->query('tipo') : 'todos';
        $categoria = array_key_exists($request->query('categoria', 'todas'), $categorias)
            ? $request->query('categoria', 'todas')
            : 'todas';
        $limit = $this->pageSize($request);
        $page = max(1, (int) $request->query('page', 1));
        $query = $this->movimentosQuery($request->user()->id, $inicio, $inicio->copy()->endOfMonth(), $tipo, $categoria);
        $total = (clone $query)->count();
        $items = (clone $query)->offset(($page - 1) * $limit)->limit($limit)->get();

        return response()->json([
            'data' => $items,
            'page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'has_more' => $page * $limit < $total,
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
        if ($categoria !== 'todas') {
            $wrapped->where('category', $categoria);
        }

        return $wrapped->orderByDesc('data_movimento')->orderBy('id');
    }
}
