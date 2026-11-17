<?php

namespace App\Http\Controllers;

use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\RecurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    private function config(Request $request): array
    {
        $tipo = $request->route('tipo');
        abort_unless(in_array($tipo, ['despesa', 'receita'], true), 404);

        return [
            $tipo,
            $tipo === 'despesa' ? 'despesas' : 'receitas',
            $tipo === 'despesa' ? 'expense_date' : 'income_date',
        ];
    }

    private function query(Request $request)
    {
        [, $relation] = $this->config($request);
        return $request->user()->{$relation}();
    }

    private function availableAccounts(Request $request, string $tipo)
    {
        $types = $tipo === 'despesa'
            ? ($request->boolean('card_only') ? ['credit_card'] : ['bank', 'cash', 'credit_card'])
            : ['bank', 'cash'];

        return $request->user()->contasFinanceiras()
            ->whereIn('type', $types)
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->orderBy('name')
            ->get();
    }

    private function linkedMovement(Request $request, string $tipo, string $legacyId)
    {
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';
        return $request->user()->movimentos()
            ->where('legacy_type', $legacyType)
            ->where('legacy_id', $legacyId)
            ->first();
    }

    private function categoryOptions(Request $request, string $tipo, bool $includeInactive = false)
    {
        $query = $request->user()->categorias()->where('type', $tipo);
        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->pluck('name', 'id');
    }

    private function validatedData(Request $request, ?string $movementId = null): array
    {
        [$tipo, , $dateField] = $this->config($request);
        $accountExists = Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id);
        if ($request->boolean('card_only')) {
            $accountExists->where('type', 'credit_card');
        }

        $data = $request->validate([
            'description' => 'required|string|max:255',
            'expense_behavior' => ['nullable', Rule::in(['fixed', 'variable'])],
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'account_id' => ['nullable', 'uuid', $accountExists],
            'payment_account_id' => ['nullable', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'paid_now' => ['nullable', Rule::in(['0', '1', 'partial'])],
            'payment_amount' => 'nullable|numeric|gt:0|decimal:0,2',
            'payment_date' => 'nullable|date',
            'actual_payment_method' => 'nullable|string|max:30',
            'payment_condition' => ['nullable', Rule::in(['cash', 'deferred', 'installments'])],
            'payment_method' => 'nullable|string|max:30',
            'due_date' => 'nullable|date',
            'installments_count' => 'nullable|integer|min:2|max:120',
            'installment_frequency' => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'card_only' => 'sometimes|boolean',
            'category_id' => [
                'required_without:category',
                'nullable',
                'uuid',
                Rule::exists('categorias', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $tipo),
            ],
            'category' => [
                'required_without:category_id', 'nullable', 'string', 'max:120',
                Rule::exists('categorias', 'slug')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $tipo),
            ],
            $dateField => 'required|date',
            'recurrence_enabled' => 'sometimes|boolean',
            'recurrence_frequency' => ['required_if:recurrence_enabled,1', Rule::in(['daily', 'weekly', 'monthly', 'yearly', 'custom'])],
            'recurrence_interval' => 'required_if:recurrence_enabled,1|integer|min:1|max:365',
            'recurrence_ends_at' => 'nullable|date|after_or_equal:' . $dateField,
        ]);

        $selectedAccountId = $data['payment_account_id'] ?? $data['account_id'] ?? null;
        $isPendingExpense = $tipo === 'despesa' && !$request->boolean('recurrence_enabled')
            && ($data['paid_now'] ?? '0') === '0' && !$selectedAccountId;
        $account = $selectedAccountId ? $request->user()->contasFinanceiras()
            ->whereKey($selectedAccountId)
            ->whereIn('type', $tipo === 'despesa'
                ? ($request->boolean('card_only') ? ['credit_card'] : ['bank', 'cash', 'credit_card'])
                : ['bank', 'cash'])
            ->where('is_operational', true)->where('status', 'active')->whereNotNull('opening_balance')->first() : null;
        if ((!$account && !$isPendingExpense) || ($account && $account->currency !== $request->user()->currency)) {
            throw ValidationException::withMessages(['payment_account_id' => 'Seleciona uma conta ativa, configurada e na moeda da tua conta.']);
        }
        if ($account) $data['account_id'] = $account->id;

        $categoryQuery = $request->user()->categorias()->where('type', $tipo);
        $category = !empty($data['category_id'])
            ? $categoryQuery->whereKey($data['category_id'])->firstOrFail()
            : $categoryQuery->where('slug', $data['category'])->firstOrFail();

        if (!$category->is_active) {
            $current = $movementId
                ? $this->query($request)->whereKey($movementId)->value('category_id')
                : null;

            if ($current !== $category->id) {
                abort(422, 'Esta categoria está inativa e não pode ser utilizada em novos movimentos.');
            }
        }

        // Mantém a coluna textual antiga compatível com o CHECK legado e clientes anteriores.
        $legacyKeys = $tipo === 'despesa'
            ? ['casa', 'alimentacao', 'transportes', 'saude', 'lazer', 'compras', 'educacao', 'outros']
            : ['salario', 'vendas', 'trabalho_extra', 'investimentos', 'reembolsos', 'outros'];

        return array_merge($data, [
            'category_id' => $category->id,
            'category' => in_array($category->slug, $legacyKeys, true) ? $category->slug : 'outros',
            'currency' => $request->user()->currency,
        ]);
    }

    private function canonicalPayload(string $tipo, array $data, string $legacyType, string $legacyId, bool $historical = false): array
    {
        [$source, $destination] = $tipo === 'despesa'
            ? [$data['account_id'], null]
            : [null, $data['account_id']];

        return [
            'type' => $tipo === 'despesa' ? 'expense' : 'income',
            'expense_behavior' => $tipo === 'despesa' ? ($data['expense_behavior'] ?? 'variable') : 'variable',
            'description' => $data['description'],
            'amount' => $data['amount'],
            'movement_date' => $data[$tipo === 'despesa' ? 'expense_date' : 'income_date'],
            'source_account_id' => $source,
            'destination_account_id' => $destination,
            'category_id' => $data['category_id'],
            'legacy_type' => $legacyType,
            'legacy_id' => $legacyId,
            'is_historical' => $historical,
        ];
    }

    private function createLinkedMovement(Request $request, array $payload, FinancialMovementService $movements, CardInvoiceService $invoices): void
    {
        $accountId = $payload['source_account_id'] ?? null;
        $account = $accountId ? $request->user()->contasFinanceiras()->whereKey($accountId)->first() : null;
        if ($payload['type'] === 'expense' && $account?->type === 'credit_card') {
            $invoices->purchase($request->user(), [
                'account_id' => $account->id,
                'description' => $payload['description'],
                'amount' => $payload['amount'],
                'movement_date' => $payload['movement_date'],
                'category_id' => $payload['category_id'] ?? null,
                'legacy_type' => $payload['legacy_type'] ?? null,
                'legacy_id' => $payload['legacy_id'] ?? null,
                'expense_behavior' => $payload['expense_behavior'] ?? 'variable',
                'is_historical' => $payload['is_historical'] ?? false,
            ]);
            return;
        }

        $movements->create($request->user(), $payload);
    }

    public function index(Request $request)
    {
        [$tipo, , $dateField] = $this->config($request);
        $cats = $this->categoryOptions($request, $tipo, true);
        $filters = $request->validate([
            'categoria' => ['nullable', 'uuid'],
            'inicio' => 'nullable|date',
            'fim' => 'nullable|date|after_or_equal:inicio',
        ]);

        $query = $this->query($request)->with('categoria')
            ->orderByDesc($dateField)->orderBy('id');

        if (!empty($filters['categoria']) && $cats->has($filters['categoria'])) {
            $query->where('category_id', $filters['categoria']);
        }
        if (!empty($filters['inicio'])) {
            $query->whereDate($dateField, '>=', $filters['inicio']);
        }
        if (!empty($filters['fim'])) {
            $query->whereDate($dateField, '<=', $filters['fim']);
        }

        $registos = $query->paginate(10)->withQueryString();
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';
        $linkedMovements = $request->user()->movimentos()
            ->where('legacy_type', $legacyType)
            ->whereIn('legacy_id', $registos->getCollection()->pluck('id'))
            ->with(['contaOrigem', 'contaDestino', 'recorrencia'])
            ->get()
            ->keyBy('legacy_id');
        $data = $dateField;

        return view('finance.index', compact('tipo', 'data', 'cats', 'registos', 'linkedMovements'));
    }

    public function create(Request $request)
    {
        [$tipo, , $dateField] = $this->config($request);
        $registo = null;
        $financialMovement = null;
        $data = $dateField;
        $cats = $this->categoryOptions($request, $tipo);
        $accounts = $this->availableAccounts($request, $tipo);
        $selectedAccountId = $request->query('account_id');
        $returnUrl = route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index');

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo', 'accounts', 'financialMovement', 'returnUrl', 'selectedAccountId'));
    }

    public function store(Request $request, FinancialMovementService $service, CardInvoiceService $invoices, RecurrenceService $recurrences, FinancialCommitmentService $commitments)
    {
        [$tipo] = $this->config($request);
        $data = $this->validatedData($request);
        if ($tipo === 'despesa' && in_array($data['payment_condition'] ?? 'cash', ['deferred', 'installments'], true)) {
            if (($data['paid_now'] ?? '0') !== '0') {
                throw ValidationException::withMessages(['paid_now' => 'Regista primeiro a obrigação como pendente; o pagamento deve ser feito em Compromissos.']);
            }
            $commitments->create($request->user(), [
                'name' => $data['description'],
                'total_amount' => $data['amount'],
                'start_date' => $data['expense_date'],
                'due_date' => $data['due_date'] ?? $data['expense_date'],
                'payment_condition' => $data['payment_condition'],
                'payment_method' => $data['payment_method'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'category_id' => $data['category_id'],
                'installments_count' => $data['installments_count'] ?? null,
                'installment_frequency' => $data['installment_frequency'] ?? 'monthly',
            ]);
            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'message' => 'Compromisso criado.']);
            }
            return redirect()->route('despesas.index')->with('success', 'Compromisso criado.');
        }
        $recurrenceEnabled = (bool) ($data['recurrence_enabled'] ?? false);
        $isPendingExpense = $tipo === 'despesa'
            && !$recurrenceEnabled
            && ($data['paid_now'] ?? '0') === '0'
            && empty($data['account_id'])
            && empty($data['payment_account_id']);
        $accountId = $isPendingExpense ? null : ($data['payment_account_id'] ?? $data['account_id'] ?? null);
        if (!$accountId && !$isPendingExpense) {
            throw ValidationException::withMessages(['payment_account_id' => 'Seleciona a conta utilizada no pagamento.']);
        }
        $dateField = $tipo === 'despesa' ? 'expense_date' : 'income_date';
        if ($recurrenceEnabled) {
            $recurrences->create($request->user(), [
                'type' => $tipo === 'despesa' ? 'expense' : 'income',
                'expense_behavior' => $tipo === 'despesa' ? ($data['expense_behavior'] ?? 'variable') : 'variable',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'category_id' => $data['category_id'],
                'account_id' => $accountId,
                'frequency' => $data['recurrence_frequency'],
                'interval_value' => $data['recurrence_interval'],
                'start_date' => $data[$dateField],
                'ends_at' => $data['recurrence_ends_at'] ?? null,
            ]);
            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'message' => ($tipo === 'despesa' ? 'Despesa' : 'Receita') . ' recorrente criada.']);
            }
            return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
                ->with('success', ($tipo === 'despesa' ? 'Despesa' : 'Receita') . ' recorrente criada.');
        }
        $expenseBehavior = $data['expense_behavior'] ?? 'variable';
        unset($data['expense_behavior'], $data['account_id'], $data['payment_account_id'], $data['paid_now'], $data['payment_amount'], $data['payment_date'], $data['actual_payment_method'], $data['payment_condition'], $data['payment_method'], $data['installments_count'], $data['installment_frequency'], $data['card_only'], $data['recurrence_enabled'], $data['recurrence_frequency'], $data['recurrence_interval'], $data['recurrence_ends_at']);
        if ($tipo !== 'despesa') unset($data['due_date']);
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';

        DB::transaction(function () use ($request, $service, $invoices, $tipo, $data, $accountId, $legacyType, $expenseBehavior, $isPendingExpense): void {
            $legacy = $this->query($request)->create($data);
            $payload = $this->canonicalPayload($tipo, array_merge($data, ['account_id' => $accountId, 'expense_behavior' => $expenseBehavior]), $legacyType, $legacy->id);
            if ($isPendingExpense) {
                $service->createPending($request->user(), array_merge($payload, ['source_account_id' => null, 'destination_account_id' => null]));
                return;
            }
            $this->createLinkedMovement($request, $payload, $service, $invoices);
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo criado.']);
        }
        if ($request->boolean('return_to_dashboard')) {
            return redirect()->route('dashboard')->with('success', 'Registo criado.');
        }

        return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
            ->with('success', 'Registo criado.');
    }

    public function edit(Request $request, string $id)
    {
        [$tipo, , $dateField] = $this->config($request);
        $registo = $this->query($request)->findOrFail($id);
        $financialMovement = $this->linkedMovement($request, $tipo, $registo->id);
        $data = $dateField;
        $cats = $this->categoryOptions($request, $tipo, true);
        $accounts = $this->availableAccounts($request, $tipo);
        $returnUrl = $this->accountReturnUrl($request, $tipo === 'despesa' ? 'despesas.index' : 'receitas.index');

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo', 'accounts', 'financialMovement', 'returnUrl'));
    }

    public function update(Request $request, string $id, FinancialMovementService $service, CardInvoiceService $invoices, RecurrenceService $recurrences)
    {
        [$tipo, , $dateField] = $this->config($request);
        $data = $this->validatedData($request, $id);
        $recurrenceEnabled = (bool) ($data['recurrence_enabled'] ?? false);
        $recurrenceData = ['frequency' => $data['recurrence_frequency'] ?? 'monthly', 'interval_value' => $data['recurrence_interval'] ?? 1, 'ends_at' => $data['recurrence_ends_at'] ?? null];
        unset($data['recurrence_enabled'], $data['recurrence_frequency'], $data['recurrence_interval'], $data['recurrence_ends_at']);
        $expenseBehavior = $data['expense_behavior'] ?? 'variable';
        unset($data['expense_behavior']);
        $isPendingExpense = $tipo === 'despesa'
            && !$recurrenceEnabled
            && ($data['paid_now'] ?? '0') === '0'
            && empty($data['payment_account_id'])
            && empty($data['account_id']);
        $accountId = $data['account_id'] ?? null;
        unset($data['account_id'], $data['payment_account_id'], $data['paid_now'], $data['payment_amount'], $data['payment_date'], $data['actual_payment_method'], $data['payment_condition'], $data['payment_method'], $data['installments_count'], $data['installment_frequency'], $data['card_only']);
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';
        $date = $data[$dateField];

        DB::transaction(function () use ($request, $service, $invoices, $tipo, $id, $data, $accountId, $legacyType, $dateField, $date, $expenseBehavior, $isPendingExpense): void {
            $legacy = $this->query($request)->whereKey($id)->lockForUpdate()->firstOrFail();
            $movement = $this->linkedMovement($request, $tipo, $legacy->id);
            if ($movement?->invoice_id) {
                $invoice = $request->user()->faturas()->whereKey($movement->invoice_id)->firstOrFail();
                if ($invoice->status !== 'open') {
                    throw ValidationException::withMessages(['expense' => 'Não é possível editar uma compra de fatura já liquidada. Estorna o movimento e regista a correção.']);
                }
            }
            $payload = $this->canonicalPayload($tipo, array_merge($data, ['account_id' => $accountId, 'expense_behavior' => $expenseBehavior]), $legacyType, $legacy->id);

            if (!$movement) {
                $cutoff = $request->user()->financial_cutoff_date?->toDateString();
                $payload['is_historical'] = $cutoff !== null && $date <= $cutoff;
                $this->createLinkedMovement($request, $payload, $service, $invoices);
            } elseif ($movement->status === 'pending') {
                $movement = $service->updatePending($request->user(), $movement, [
                    'description' => $payload['description'],
                    'amount' => $payload['amount'],
                    'movement_date' => $payload['movement_date'],
                    'category_id' => $payload['category_id'],
                    'expense_behavior' => $payload['expense_behavior'],
                ]);
                if ($isPendingExpense) {
                    // A edição de uma despesa que continua por pagar não pode liquidá-la.
                } elseif ($movement->recurrence_id) {
                    $service->confirmPending($request->user(), $movement, [
                        ($tipo === 'despesa' ? 'source_account_id' : 'destination_account_id') => $accountId,
                    ]);
                } else {
                    $service->settle($request->user(), $movement, [
                        ($tipo === 'despesa' ? 'source_account_id' : 'destination_account_id') => $accountId,
                    ]);
                }
            } elseif ($movement->status === 'settled') {
                $sameFinancialData = $movement->type === ($tipo === 'despesa' ? 'expense' : 'income')
                    && (string) $movement->description === (string) $payload['description']
                    && (string) $movement->amount === (string) $payload['amount']
                    && $movement->movement_date->toDateString() === (string) $payload['movement_date']
                    && (string) $movement->category_id === (string) $payload['category_id']
                    && ($tipo !== 'despesa' || $movement->expense_behavior === $payload['expense_behavior']);
                if (!$sameFinancialData) {
                    throw ValidationException::withMessages([
                        'movement' => 'Um movimento liquidado não pode ser editado. Estorna-o e regista a correção como um novo movimento.',
                    ]);
                }
                $currentAccountId = $tipo === 'despesa'
                    ? $movement->source_account_id
                    : $movement->destination_account_id;
                if ($movement->invoice_id && (string) $currentAccountId !== (string) $accountId) {
                    throw ValidationException::withMessages([
                        'movement' => 'Não é possível reatribuir a conta de uma compra ligada a uma fatura; corrige primeiro a fatura.',
                    ]);
                }
                $service->assignLegacyAccount($request->user(), $movement, $accountId);
            } else {
                if ($movement->installment_id || $movement->debt_id) {
                    throw ValidationException::withMessages(['movement' => 'Este movimento está ligado a um compromisso. Revê-o em Parcelamentos.']);
                }
                // Preserva a regra e a data original da ocorrência ao substituir o movimento.
                // Caso contrário, desmarcar a repetição não encontra a regra para a desativar.
                $recurrenceId = $movement->recurrence_id;
                $occurrenceDate = $movement->recurrence_occurrence_date;

                $cutoff = $request->user()->financial_cutoff_date?->toDateString();
                $payload['is_historical'] = (bool) $movement->is_historical || ($cutoff !== null && $date <= $cutoff);
                $movement->historico()->delete();
                $movement->delete();
                $this->createLinkedMovement($request, $payload, $service, $invoices);

                if ($recurrenceId) {
                    $replacement = $this->linkedMovement($request, $tipo, $legacy->id);
                    $replacement?->update([
                        'recurrence_id' => $recurrenceId,
                        'recurrence_occurrence_date' => $occurrenceDate ?: $date,
                    ]);
                }
            }

            $legacy->update($data);
        });

        $updatedMovement = $this->linkedMovement($request, $tipo, $id);
        if ($updatedMovement) {
            $recurrences->syncEditedMovement($request->user(), $updatedMovement, array_merge([
                'description' => $data['description'], 'amount' => $data['amount'],
                'category_id' => $data['category_id'], 'account_id' => $accountId,
                'expense_behavior' => $expenseBehavior,
            ], $recurrenceData), $recurrenceEnabled);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo atualizado.']);
        }
        if ($request->boolean('return_to_dashboard')) {
            return redirect()->route('dashboard')->with('success', 'Registo atualizado.');
        }
        if ($request->filled('return_to')) {
            return redirect($this->accountReturnUrl($request, $tipo === 'despesa' ? 'despesas.index' : 'receitas.index'))
                ->with('success', 'Registo atualizado.');
        }

        return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
            ->with('success', 'Registo atualizado.');
    }

    public function destroy(Request $request, string $id, FinancialMovementService $service)
    {
        [$tipo] = $this->config($request);

        DB::transaction(function () use ($request, $tipo, $id, $service): void {
            $legacy = $this->query($request)->whereKey($id)->lockForUpdate()->firstOrFail();
            $movement = $this->linkedMovement($request, $tipo, $legacy->id);

            if ($movement) {
                $service->deleteAsCorrection(
                    $request->user(),
                    $movement,
                    'Registo legado eliminado pelo utilizador.'
                );
            }

            // O movimento canónico é mantido como void para auditoria.
            // A representação legada deixa de aparecer nas páginas antigas.
            $legacy->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo removido e saldos atualizados.']);
        }
        if ($request->filled('return_to')) {
            return redirect($this->accountReturnUrl($request, $tipo === 'despesa' ? 'despesas.index' : 'receitas.index'))
                ->with('success', 'Registo removido e saldos atualizados.');
        }

        return back()->with('success', 'Registo removido e saldos atualizados.');
    }

    private function accountReturnUrl(Request $request, string $fallbackRoute): string
    {
        $accountId = (string) $request->input('return_to', '');
        if ($accountId !== '' && $request->user()->contasFinanceiras()->whereKey($accountId)->exists()) {
            return route('financial.accounts.show', $accountId);
        }

        return route($fallbackRoute);
    }

}
