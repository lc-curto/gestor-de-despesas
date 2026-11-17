<?php

namespace App\Http\Controllers;

use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $expenseCategoryBehaviors = $user->categorias()->where('type', 'despesa')->pluck('expense_behavior', 'id')->all();
        $categoriasReceita = $user->categorias()->where('type', 'receita')->orderBy('name')->pluck('name', 'id')->all();
        $categorias = $categoriasDespesa + $categoriasReceita;
        $requestedCategory = (string) $request->query('categoria', 'all');
        $filtroCategoria = $requestedCategory === 'all' || array_key_exists($requestedCategory, $categorias)
            ? $requestedCategory : 'all';

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')->from('movimentos')
                    ->where('movimentos.legacy_type', 'despesa')
                    ->whereColumn('movimentos.legacy_id', 'despesas.id')
                    ->where('movimentos.status', 'void');
            })->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')->from('movimentos')
                    ->where('movimentos.legacy_type', 'receita')
                    ->whereColumn('movimentos.legacy_id', 'receitas.id')
                    ->where('movimentos.status', 'void');
            })->sum('amount');

        // Estes totais são económicos por mês: incluem compras de cartão na data da
        // compra e não somam novamente as transferências de pagamento da fatura.
        // A lista e os seus contadores abaixo têm, deliberadamente, outro âmbito.
        // Registos novos vivem no núcleo; o legado acima permanece apenas para compatibilidade.
        $totalDespesas += (float) $user->movimentos()->whereNull('legacy_type')->where('type', 'expense')
            ->whereIn('status', ['pending', 'partial', 'settled'])->whereNull('settles_movement_id')
            ->where(fn ($query) => $query->whereNull('invoice_id')->orWhere('is_settlement_parent', false))
            ->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalDespesas -= (float) $user->movimentos()->whereNull('legacy_type')->where('invoice_charge_type', 'credit')
            ->where('status', 'settled')->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalReceitas += (float) $user->movimentos()->whereNull('legacy_type')->where('type', 'income')
            ->whereIn('status', ['pending', 'partial', 'settled'])->whereNull('settles_movement_id')->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');

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
            ->whereIn('status', ['pending', 'partial'])
            ->whereHas('divida', fn ($query) => $query
                ->where('status', '!=', 'cancelled')
                ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card'])))
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');
        $pendingPaymentTotal = $openInvoiceTotal + $openInstallmentTotal;
        $pendingPaymentCount = $invoicePage->getCollection()->filter(fn ($invoice) => (float) $invoice->remaining_amount > 0)->count()
            + $user->parcelasDivida()->whereIn('status', ['pending', 'partial'])
                ->whereHas('divida', fn ($query) => $query
                    ->where('status', '!=', 'cancelled')
                    ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card'])))
                ->count();

        // A primeira página carrega os dois tipos de movimento. O filtro de tipo é
        // aplicado no cliente sem uma segunda requisição ao abrir o Dashboard.
        $query = $this->movimentosQuery($user->id, $inicio, $fim, 'todos', $filtroCategoria);
        [$totalDespesasFiltradas, $totalReceitasFiltradas] = $this->contarMovimentos($query);
        $totalMovimentos = $totalDespesasFiltradas + $totalReceitasFiltradas;
        $limit = $this->pageSize($request);
        $movimentos = (clone $query)->limit($limit)->get();
        $movimentosVisiveis = $movimentos->filter(
            fn ($movimento) => $filtroTipo === 'todos' || $movimento->tipo === $filtroTipo
        )->values();

        $movimentosIniciais = $movimentos->map(function ($item) {
            return [
                'id' => $item->id,
                'financial_movement_id' => $item->financial_movement_id,
                'is_invoice_payment' => (bool) ($item->is_invoice_payment ?? false),
                'tipo' => $item->tipo,
                'description' => $item->description,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'category' => $item->category,
                'category_label' => $item->category_label,
                'category_icon' => $item->category_icon,
                'category_color' => $item->category_color,
                'data_movimento' => $item->data_movimento,
                'due_date' => $item->due_date ?? null,
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
            'categoriasReceita', 'expenseCategoryBehaviors', 'filtroTipo', 'filtroCategoria',
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
        if ($categoria !== 'all' && ! in_array($categoria, $categorias, true)) {
            $categoria = 'all';
        }

        $limit = $this->pageSize($request);
        $page = min(100000, max(1, (int) $request->query('page', 1)));
        $offset = ($page - 1) * $limit;

        $query = $this->movimentosQuery($user->id, $inicio, $fim, 'todos', $categoria);
        [$totalDespesasFiltradas, $totalReceitasFiltradas] = $this->contarMovimentos($query);
        $total = $totalDespesasFiltradas + $totalReceitasFiltradas;
        $items = (clone $query)->offset($offset)->limit($limit)->get();

        $totalDespesas = (float) $user->despesas()
            ->whereBetween('expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')->from('movimentos')
                    ->where('movimentos.legacy_type', 'despesa')
                    ->whereColumn('movimentos.legacy_id', 'despesas.id')
                    ->where('movimentos.status', 'void');
            })->sum('amount');
        $totalReceitas = (float) $user->receitas()
            ->whereBetween('income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')->from('movimentos')
                    ->where('movimentos.legacy_type', 'receita')
                    ->whereColumn('movimentos.legacy_id', 'receitas.id')
                    ->where('movimentos.status', 'void');
            })->sum('amount');

        $totalDespesas += (float) $user->movimentos()->whereNull('legacy_type')->where('type', 'expense')
            ->whereIn('status', ['pending', 'partial', 'settled'])->whereNull('settles_movement_id')
            ->where(fn ($query) => $query->whereNull('invoice_id')->orWhere('is_settlement_parent', false))
            ->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalDespesas -= (float) $user->movimentos()->whereNull('legacy_type')->where('invoice_charge_type', 'credit')
            ->where('status', 'settled')->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');
        $totalReceitas += (float) $user->movimentos()->whereNull('legacy_type')->where('type', 'income')
            ->whereIn('status', ['pending', 'partial', 'settled'])->whereNull('settles_movement_id')->whereBetween('movement_date', [$inicio->toDateString(), $fim->toDateString()])->sum('amount');

        $items = $items->map(function ($item) {
            if ($item->data_movimento !== null) {
                $item->data_movimento = Carbon::parse($item->data_movimento)->toDateString();
            }

            return $item;
        });

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
                throw new \InvalidArgumentException;
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

    /** Os contadores devem ter exatamente os mesmos filtros e uniões da listagem. */
    private function contarMovimentos(Builder $query): array
    {
        $counts = (clone $query)->reorder()->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN 1 ELSE 0 END), 0) AS despesas, COALESCE(SUM(CASE WHEN tipo = 'receita' THEN 1 ELSE 0 END), 0) AS receitas")->first();

        return [(int) $counts->despesas, (int) $counts->receitas];
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
            ->leftJoin('recorrencias as recurrence_rules', 'recurrence_rules.id', '=', 'financial_movements.recurrence_id')
            ->where('despesas.user_id', $userId)
            ->whereBetween('despesas.expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(fn ($q) => $q->whereNull('financial_movements.status')->orWhere('financial_movements.status', '!=', 'void'))
            ->whereNull('financial_movements.invoice_id')
            ->selectRaw("despesas.id, financial_movements.id AS financial_movement_id, 0 AS is_invoice_payment, 'despesa' AS tipo, despesas.description, despesas.amount,
                CASE WHEN financial_movements.is_settlement_parent = 1 THEN CASE WHEN (financial_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = financial_movements.user_id
                        AND settlement_events.settles_movement_id = financial_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) < 0 THEN 0 ELSE (financial_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = financial_movements.user_id
                        AND settlement_events.settles_movement_id = financial_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) END ELSE despesas.amount END AS remaining_amount,
                despesas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, despesas.expense_date AS data_movimento, financial_movements.source_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical, financial_movements.expense_behavior AS expense_behavior, despesas.due_date AS due_date, financial_movements.recurrence_id AS recurrence_id, recurrence_rules.frequency AS recurrence_frequency, recurrence_rules.interval_value AS recurrence_interval, recurrence_rules.ends_at AS recurrence_ends_at");

        // Conserva-se o pagamento visível como saída, com is_invoice_payment, pois
        // a interface só conhece despesa/receita. No núcleo continua a ser uma
        // transferência e não entra no total económico de despesas acima.
        $cardPayments = DB::table('movimentos as card_payments')
            ->join('contas_financeiras as source_accounts', 'source_accounts.id', '=', 'card_payments.source_account_id')
            ->join('users as movement_users', 'movement_users.id', '=', 'card_payments.user_id')
            ->where('card_payments.user_id', $userId)
            ->where('card_payments.type', 'transfer')
            ->where('card_payments.status', 'settled')
            ->whereNotNull('card_payments.invoice_id')
            ->whereBetween('card_payments.movement_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("card_payments.id, card_payments.id AS financial_movement_id, 1 AS is_invoice_payment, CASE WHEN source_accounts.type = 'credit_card' THEN 'receita' ELSE 'despesa' END AS tipo, card_payments.description, card_payments.amount, card_payments.amount AS remaining_amount, movement_users.currency, NULL AS category, CASE WHEN source_accounts.type = 'credit_card' THEN 'Estorno de pagamento de fatura' ELSE 'Pagamento de fatura' END AS category_label, 'credit-card' AS category_icon, '#64748b' AS category_color, card_payments.movement_date AS data_movimento, card_payments.source_account_id AS account_id, card_payments.status AS movement_status, 0 AS requires_account_assignment, 0 AS is_historical, 'variable' AS expense_behavior, NULL AS due_date, NULL AS recurrence_id, NULL AS recurrence_frequency, NULL AS recurrence_interval, NULL AS recurrence_ends_at");

        $receitas = DB::table('receitas')
            ->join('categorias', 'categorias.id', '=', 'receitas.category_id')
            ->leftJoin('movimentos as financial_movements', function ($join) use ($userId) {
                $join->on('financial_movements.legacy_id', '=', 'receitas.id')
                    ->where('financial_movements.legacy_type', '=', 'receita')
                    ->where('financial_movements.user_id', '=', $userId);
            })
            ->leftJoin('recorrencias as recurrence_rules', 'recurrence_rules.id', '=', 'financial_movements.recurrence_id')
            ->where('receitas.user_id', $userId)
            ->whereBetween('receitas.income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(fn ($q) => $q->whereNull('financial_movements.status')->orWhere('financial_movements.status', '!=', 'void'))
            ->selectRaw("receitas.id, financial_movements.id AS financial_movement_id, 0 AS is_invoice_payment, 'receita' AS tipo, receitas.description, receitas.amount,
                CASE WHEN financial_movements.is_settlement_parent = 1 THEN CASE WHEN (financial_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = financial_movements.user_id
                        AND settlement_events.settles_movement_id = financial_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) < 0 THEN 0 ELSE (financial_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = financial_movements.user_id
                        AND settlement_events.settles_movement_id = financial_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) END ELSE receitas.amount END AS remaining_amount,
                receitas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, receitas.income_date AS data_movimento, financial_movements.destination_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical, financial_movements.expense_behavior AS expense_behavior, NULL AS due_date, financial_movements.recurrence_id AS recurrence_id, recurrence_rules.frequency AS recurrence_frequency, recurrence_rules.interval_value AS recurrence_interval, recurrence_rules.ends_at AS recurrence_ends_at");

        $canonical = DB::table('movimentos as canonical_movements')
            ->leftJoin('categorias as canonical_categories', 'canonical_categories.id', '=', 'canonical_movements.category_id')
            ->leftJoin('recorrencias as canonical_rules', 'canonical_rules.id', '=', 'canonical_movements.recurrence_id')
            ->join('users as canonical_users', 'canonical_users.id', '=', 'canonical_movements.user_id')
            ->where('canonical_movements.user_id', $userId)
            ->whereNull('canonical_movements.legacy_type')
            ->whereIn('canonical_movements.type', ['income', 'expense'])
            ->whereIn('canonical_movements.status', ['pending', 'partial', 'settled'])
            // Oculta apenas compras associadas a fatura, não receitas nem
            // previsões ainda sem fatura (mesmo quando têm conta de cartão).
            ->where(fn ($query) => $query->where('canonical_movements.type', '!=', 'expense')->orWhereNull('canonical_movements.invoice_id'))
            ->whereBetween('canonical_movements.movement_date', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("canonical_movements.id, canonical_movements.id AS financial_movement_id, 0 AS is_invoice_payment, CASE WHEN canonical_movements.type = 'income' THEN 'receita' ELSE 'despesa' END AS tipo, canonical_movements.description, canonical_movements.amount,
                CASE WHEN canonical_movements.is_settlement_parent = 1 THEN CASE WHEN (canonical_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = canonical_movements.user_id
                        AND settlement_events.settles_movement_id = canonical_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) < 0 THEN 0 ELSE (canonical_movements.amount - COALESCE((
                    SELECT SUM(CASE WHEN settlement_events.reversal_of_id IS NULL THEN settlement_events.amount ELSE -settlement_events.amount END)
                    FROM movimentos AS settlement_events
                    WHERE settlement_events.user_id = canonical_movements.user_id
                        AND settlement_events.settles_movement_id = canonical_movements.id
                        AND settlement_events.status = 'settled'
                ), 0)) END ELSE canonical_movements.amount END AS remaining_amount,
                canonical_users.currency, canonical_categories.id AS category, canonical_categories.name AS category_label, canonical_categories.icon AS category_icon, canonical_categories.color AS category_color, canonical_movements.movement_date AS data_movimento, CASE WHEN canonical_movements.type = 'income' THEN canonical_movements.destination_account_id ELSE canonical_movements.source_account_id END AS account_id, canonical_movements.status AS movement_status, canonical_movements.requires_account_assignment AS requires_account_assignment, canonical_movements.is_historical AS is_historical, canonical_movements.expense_behavior AS expense_behavior, NULL AS due_date, canonical_movements.recurrence_id AS recurrence_id, canonical_rules.frequency AS recurrence_frequency, canonical_rules.interval_value AS recurrence_interval, canonical_rules.ends_at AS recurrence_ends_at");

        if ($tipo === 'despesa') {
            $query = $despesas->unionAll((clone $canonical)->where('canonical_movements.type', 'expense'))->unionAll($cardPayments);
        } elseif ($tipo === 'receita') {
            $query = $receitas->unionAll((clone $canonical)->where('canonical_movements.type', 'income'));
        } else {
            $query = $despesas->unionAll($receitas)->unionAll($canonical)->unionAll($cardPayments);
        }

        $wrapped = DB::query()->fromSub($query, 'movimentos');
        if ($categoria !== 'all') {
            $wrapped->where('category', $categoria);
        }

        return $wrapped->orderByDesc('data_movimento')->orderBy('id');
    }
}
