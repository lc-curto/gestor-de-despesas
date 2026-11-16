<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\DashboardMovementQuery;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AccountBalanceService $balances, CardInvoiceService $invoices)
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

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(function ($query) {
                $query->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'despesa')->whereColumn('movimentos.legacy_id', 'despesas.id');
                })->orWhereExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'despesa')->whereColumn('movimentos.legacy_id', 'despesas.id')->where('movimentos.status', 'settled');
                });
            })->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(function ($query) {
                $query->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'receita')->whereColumn('movimentos.legacy_id', 'receitas.id');
                })->orWhereExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'receita')->whereColumn('movimentos.legacy_id', 'receitas.id')->where('movimentos.status', 'settled');
                });
            })->sum('amount');

        $operationalAccounts = $user->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->whereNotNull('opening_balance_date')
            ->get();
        $availableBalance = $operationalAccounts->sum(fn ($account) => (float) $balances->balance($account));

        $invoicePage = $invoices->listInvoices($user);
        $openInvoiceTotal = (float) $invoicePage->getCollection()->sum('remaining_amount');
        $openInstallmentTotal = (float) $user->parcelasDivida()
            ->where('status', '!=', 'paid')
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');
        $pendingPaymentTotal = $openInvoiceTotal + $openInstallmentTotal;
        $pendingPaymentCount = $invoicePage->getCollection()->filter(fn ($invoice) => (float) $invoice->remaining_amount > 0)->count()
            + $user->parcelasDivida()->where('status', '!=', 'paid')->count();

        // A primeira página carrega os dois tipos de movimento. O filtro de tipo é
        // aplicado no cliente sem uma segunda requisição ao abrir o Dashboard.
        $despesasFiltradas = $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as cancelled')->where('cancelled.user_id', $user->id)->where('cancelled.legacy_type', 'despesa')->whereColumn('cancelled.legacy_id', 'despesas.id')->where('cancelled.status', 'void'));
        $receitasFiltradas = $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as cancelled')->where('cancelled.user_id', $user->id)->where('cancelled.legacy_type', 'receita')->whereColumn('cancelled.legacy_id', 'receitas.id')->where('cancelled.status', 'void'));

        if ($filtroCategoria !== 'all') {
            $despesasFiltradas->where('category_id', $filtroCategoria);
            $receitasFiltradas->where('category_id', $filtroCategoria);
        }

        $totalDespesasFiltradas = (clone $despesasFiltradas)->count();
        $totalReceitasFiltradas = (clone $receitasFiltradas)->count();
        $totalMovimentos = $totalDespesasFiltradas + $totalReceitasFiltradas;

        $query = app(DashboardMovementQuery::class)->build($user->id, $inicio, $fim, 'todos', $filtroCategoria);
        $limit = $this->pageSize($request);
        $movimentos = (clone $query)->limit($limit)->get();
        $movimentosVisiveis = $movimentos->filter(
            fn ($movimento) => $filtroTipo === 'todos' || $movimento->tipo === $filtroTipo
        )->values();

        $movimentosIniciais = $movimentos->map(function ($item) {
            return [
                'id' => $item->id,
                'financial_movement_id' => $item->financial_movement_id,
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
                'expense_behavior' => $item->expense_behavior ?? 'variable',
                'recurrence_id' => $item->recurrence_id,
                'recurrence_frequency' => $item->recurrence_frequency,
                'recurrence_interval' => $item->recurrence_interval,
                'recurrence_ends_at' => $item->recurrence_ends_at,
            ];
        })->values();

        return view('dashboard', compact(
            'inicio', 'mes', 'movimentos', 'movimentosVisiveis', 'movimentosIniciais', 'categorias', 'categoriasDespesa',
            'categoriasReceita', 'filtroTipo', 'filtroCategoria',
            'totalDespesas', 'totalReceitas', 'availableBalance', 'pendingPaymentTotal', 'pendingPaymentCount', 'totalMovimentos', 'totalDespesasFiltradas', 'totalReceitasFiltradas', 'limit'
        ))->with('moeda', $user->currency)->with('accounts', $accounts);
    }

    public function paginaMovimentos(Request $request, AccountBalanceService $balances, CardInvoiceService $invoices)
    {
        if ($request->expectsJson()) {
            return $this->movimentos($request);
        }
        $dashboard = $this->__invoke($request, $balances, $invoices);

        return view('movements', $dashboard->getData());
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

        $despesasQuery = $user->despesas()->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as cancelled')->where('cancelled.user_id', $user->id)->where('cancelled.legacy_type', 'despesa')->whereColumn('cancelled.legacy_id', 'despesas.id')->where('cancelled.status', 'void'));
        $receitasQuery = $user->receitas()->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as cancelled')->where('cancelled.user_id', $user->id)->where('cancelled.legacy_type', 'receita')->whereColumn('cancelled.legacy_id', 'receitas.id')->where('cancelled.status', 'void'));
        if ($categoria !== 'all') {
            $despesasQuery->where('category_id', $categoria);
            $receitasQuery->where('category_id', $categoria);
        }

        $totalDespesasFiltradas = (clone $despesasQuery)->count();
        $totalReceitasFiltradas = (clone $receitasQuery)->count();

        $query = app(DashboardMovementQuery::class)->build($user->id, $inicio, $fim, 'todos', $categoria);
        $items = (clone $query)->offset($offset)->limit($limit)->get();
        $total = $totalDespesasFiltradas + $totalReceitasFiltradas;

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(function ($query) {
                $query->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'despesa')->whereColumn('movimentos.legacy_id', 'despesas.id');
                })->orWhereExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'despesa')->whereColumn('movimentos.legacy_id', 'despesas.id')->where('movimentos.status', 'settled');
                });
            })->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(function ($query) {
                $query->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'receita')->whereColumn('movimentos.legacy_id', 'receitas.id');
                })->orWhereExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('movimentos')->where('movimentos.legacy_type', 'receita')->whereColumn('movimentos.legacy_id', 'receitas.id')->where('movimentos.status', 'settled');
                });
            })->sum('amount');

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


}
