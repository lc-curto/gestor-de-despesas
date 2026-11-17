<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Divida;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialCoreController extends Controller
{
    public function commitments(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->dividas()->with('parcelas')->latest()->paginate(30)]);
    }

    public function payCommitmentInstallment(Request $request, string $id, string $installmentId, FinancialCommitmentService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'amount' => 'nullable|numeric|gt:0|decimal:0,2',
            'payment_date' => 'nullable|date',
        ]);
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();
        $installment = $debt->parcelas()->whereKey($installmentId)->firstOrFail();

        return response()->json(['data' => $service->payInstallment($request->user(), $debt, $installment, $data)]);
    }

    public function storeCommitment(Request $request, FinancialCommitmentService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'merchant' => 'nullable|string|max:160',
            'total_amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'start_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'payment_condition' => ['required', Rule::in(['cash', 'deferred', 'installments'])],
            // Compromissos representam obrigações ainda não liquidadas.
            // A liquidação é registada posteriormente em cada prestação.
            'paid_now' => ['required', Rule::in(['0'])],
            'payment_method' => 'nullable|string|max:30',
            'installments_count' => [Rule::requiredIf($request->input('payment_condition') === 'installments'), 'nullable', 'integer', 'min:2', 'max:120'],
            'installment_frequency' => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'account_id' => ['nullable', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)->where('type', 'despesa')],
            'origin_invoice_id' => ['nullable', 'uuid', Rule::exists('faturas', 'id')->where('user_id', $request->user()->id)],
            'initial_payment' => ['sometimes', 'array'],
            'initial_payment.account_id' => ['required_with:initial_payment', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)->whereIn('type', ['bank', 'cash'])->where('status', 'active')->where('is_operational', true)],
            'initial_payment.amount' => 'required_with:initial_payment|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'initial_payment.payment_date' => 'required_with:initial_payment|date|before_or_equal:today',
        ]);
        $initialPayment = $data['initial_payment'] ?? null;
        unset($data['initial_payment']);
        $debt = DB::transaction(function () use ($request, $service, $data, $initialPayment): Divida {
            $debt = $service->create($request->user(), $data);
            if ($initialPayment) {
                $firstInstallment = $debt->parcelas->sortBy('installment_number')->firstOrFail();
                $service->payInstallment($request->user(), $debt, $firstInstallment, [
                    'account_id' => $initialPayment['account_id'],
                    'amount' => $initialPayment['amount'],
                    'payment_date' => $initialPayment['payment_date'],
                ]);
            }

            return $debt->fresh()->load('parcelas');
        });

        return response()->json(['data' => $debt], 201);
    }

    public function updateCommitment(Request $request, string $id, FinancialCommitmentService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'merchant' => 'nullable|string|max:160',
            'due_date' => 'sometimes|required|date|after_or_equal:today',
        ]);
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->update($request->user(), $debt, $data)]);
    }

    public function renegotiateCommitment(Request $request, string $id, FinancialCommitmentService $service): JsonResponse
    {
        $data = $request->validate([
            'installments_count' => 'required|integer|min:1|max:120',
            'installment_frequency' => ['required', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'due_date' => 'required|date|after_or_equal:today',
            'remaining_amount' => 'nullable|numeric|gt:0|decimal:0,2|max:9999999999.99',
        ]);
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->renegotiate($request->user(), $debt, $data), 'message' => 'Parcelamento renegociado.']);
    }

    public function archiveCommitment(Request $request, string $id, FinancialCommitmentService $service): JsonResponse
    {
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->archive($request->user(), $debt), 'message' => 'Compra arquivada.']);
    }

    public function deleteCommitment(Request $request, string $id, FinancialCommitmentService $service): JsonResponse
    {
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();
        $service->deleteUnused($request->user(), $debt);

        return response()->json(['message' => 'Compra eliminada.']);
    }

    public function cancelCommitment(Request $request, string $id, FinancialCommitmentService $service): JsonResponse
    {
        $debt = $request->user()->dividas()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->cancel($request->user(), $debt)]);
    }

    public function storeRecurrence(Request $request, RecurrenceService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'description' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'category_id' => ['required', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'account_id' => ['nullable', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly', 'custom'])],
            'interval_value' => 'required|integer|min:1|max:365',
            'start_date' => 'required|date|after_or_equal:today',
            'ends_at' => 'nullable|date|after_or_equal:start_date',
        ]);
        $rule = $service->create($request->user(), $data);

        return response()->json(['data' => $rule, 'message' => 'Recorrência criada; previsões geradas para os próximos 90 dias.'], 201);
    }

    public function setRecurrenceStatus(Request $request, string $id, RecurrenceService $service): JsonResponse
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $rule = $request->user()->recorrencias()->whereKey($id)->firstOrFail();
        $updated = $service->setActive($request->user(), $rule, (bool) $data['is_active']);

        return response()->json(['data' => $updated, 'message' => $updated->is_active ? 'Recorrência retomada.' : 'Recorrência pausada.']);
    }

    public function storeCardPurchase(Request $request, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date|before_or_equal:today',
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'expense_behavior' => ['nullable', Rule::in(['fixed', 'variable'])],
        ]);

        return response()->json(['data' => $service->purchase($request->user(), $data)], 201);
    }

    public function invoices(Request $request, CardInvoiceService $service): JsonResponse
    {
        return response()->json(['data' => $service->listInvoices($request->user())]);
    }

    public function payInvoice(Request $request, string $id, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
            'amount' => 'nullable|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'payment_date' => 'required|date',
            'allocation' => 'sometimes|array:principal,interest,fees,taxes,other',
            'allocation.principal' => 'nullable|numeric|gte:0|decimal:0,2',
            'allocation.interest' => 'nullable|numeric|gte:0|decimal:0,2',
            'allocation.fees' => 'nullable|numeric|gte:0|decimal:0,2',
            'allocation.taxes' => 'nullable|numeric|gte:0|decimal:0,2',
            'allocation.other' => 'nullable|numeric|gte:0|decimal:0,2',
        ]);
        $invoice = $request->user()->faturas()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->pay(
            $request->user(),
            $invoice,
            $data['account_id'],
            $data['payment_date'],
            isset($data['amount']) ? (string) $data['amount'] : null,
            $data['allocation'] ?? null
        )]);
    }

    public function addInvoiceCharge(Request $request, string $id, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'charge_type' => ['required', Rule::in(['interest', 'late_fee', 'commission', 'tax', 'other'])],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date|before_or_equal:today',
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)->where('type', 'despesa')],
        ]);
        $invoice = $request->user()->faturas()->whereKey($id)->firstOrFail();

        return response()->json([
            'data' => $service->addCharge($request->user(), $invoice, $data),
            'message' => 'Encargo registado na fatura.',
        ], 201);
    }

    public function closeInvoice(Request $request, string $id, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'statement_amount' => 'required|numeric|gte:0|decimal:0,2|max:9999999999.99',
        ]);
        $invoice = $request->user()->faturas()->whereKey($id)->firstOrFail();

        return response()->json([
            'data' => $service->closeInvoice($request->user(), $invoice, (string) $data['statement_amount']),
            'message' => 'Fatura fechada e reconciliada com o extrato.',
        ]);
    }

    public function addInvoiceCredit(Request $request, string $id, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'purchase_movement_id' => ['required', 'uuid', Rule::exists('movimentos', 'id')->where('user_id', $request->user()->id)],
            'origin_invoice_id' => ['nullable', 'uuid', Rule::exists('faturas', 'id')->where('user_id', $request->user()->id)],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date|before_or_equal:today',
        ]);
        $invoice = $request->user()->faturas()->whereKey($id)->firstOrFail();

        return response()->json([
            'data' => $service->addCredit($request->user(), $invoice, $data),
            'message' => 'Crédito associado à compra e imputado à fatura.',
        ], 201);
    }

    public function updateCardSettings(Request $request, string $id, AccountBalanceService $balances): JsonResponse
    {
        foreach (['closing_day', 'due_day'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^\\d+$/', $value)) {
                $request->merge([$field => (int) $value]);
            }
        }

        $data = $request->validate([
            'closing_day' => 'required|integer|between:1,31',
            'due_day' => 'required|integer|between:1,31',
            'credit_limit' => 'nullable|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'opening_debt_amount' => 'sometimes|required|numeric|min:0|decimal:0,2|max:9999999999.99',
        ]);
        $card = $request->user()->contasFinanceiras()->whereKey($id)->where('type', 'credit_card')->where('is_operational', true)->whereIn('status', ['active', 'suspended'])->firstOrFail();
        $hasCardMovements = $request->user()->movimentos()->where(function ($query) use ($card) {
            $query->where('source_account_id', $card->id)->orWhere('destination_account_id', $card->id);
        })->exists();
        $openingDebtChanged = array_key_exists('opening_debt_amount', $data)
            && $balances->toCents((string) $data['opening_debt_amount']) !== $balances->toCents((string) ($card->opening_debt_amount ?? '0.00'));
        if ($openingDebtChanged && $hasCardMovements) {
            return response()->json(['message' => 'A dívida inicial só pode ser corrigida antes de existirem movimentos no cartão.'], 422);
        }
        if ($openingDebtChanged && isset($data['credit_limit']) && $balances->toCents((string) $data['opening_debt_amount']) > $balances->toCents((string) $data['credit_limit'])) {
            return response()->json(['message' => 'A dívida inicial não pode ultrapassar o limite total do cartão.'], 422);
        }
        $used = $openingDebtChanged && ! $hasCardMovements ? (float) $data['opening_debt_amount'] : abs((float) $balances->balance($card));
        if (isset($data['credit_limit']) && (float) $data['credit_limit'] + 0.00001 < $used) {
            return response()->json(['message' => 'O limite não pode ser inferior ao valor já utilizado.'], 422);
        }
        if ($openingDebtChanged) {
            $data['opening_balance'] = '-'.number_format((float) $data['opening_debt_amount'], 2, '.', '');
        }
        $card->update($data);

        return response()->json(['data' => $card->refresh()]);
    }

    public function accounts(Request $request, AccountBalanceService $balances): JsonResponse
    {
        $accounts = $request->user()->contasFinanceiras()
            ->where('type', '!=', 'legacy')
            ->orderBy('created_at')
            ->get()
            ->map(function (ContaFinanceira $account) use ($balances) {
                $account->current_balance = null;
                if ($account->is_operational && $account->opening_balance !== null) {
                    $account->current_balance = $balances->balance($account);
                }

                return $account;
            });

        return response()->json(['data' => $accounts]);
    }

    public function storeAccount(Request $request, FinancialAccountService $service): JsonResponse
    {
        // Inputs type="number" can arrive as "01". Normalize valid digit strings
        // before Laravel's integer validator, which rejects leading zeroes.
        foreach (['closing_day', 'due_day'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^\\d+$/', $value)) {
                $request->merge([$field => (int) $value]);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(['bank', 'cash', 'credit_card'])],
            'opening_balance' => 'nullable|numeric|decimal:0,2|between:-9999999999.99,9999999999.99',
            'opening_debt_amount' => 'nullable|numeric|min:0|decimal:0,2|max:9999999999.99',
            'opening_balance_date' => 'required_with:opening_balance|date|before_or_equal:today',
            'allow_negative_balance' => 'sometimes|boolean',
            'closing_day' => 'required_if:type,credit_card|nullable|integer|between:1,31',
            'due_day' => 'required_if:type,credit_card|nullable|integer|between:1,31',
            'credit_limit' => 'nullable|numeric|gt:0|decimal:0,2|max:9999999999.99',
        ]);

        if (($data['type'] ?? null) === 'credit_card' && isset($data['opening_debt_amount'], $data['credit_limit']) && (float) $data['opening_debt_amount'] > (float) $data['credit_limit']) {
            throw ValidationException::withMessages(['opening_debt_amount' => 'A utilização inicial não pode ultrapassar o limite total do cartão.']);
        }

        $account = $service->create($request->user(), $data);

        return response()->json(['data' => $account], 201);
    }

    public function updateAccount(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $account = $request->user()->contasFinanceiras()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->rename($request->user(), $account, $data['name'])]);
    }

    public function updateAccountSettings(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate(['allow_negative_balance' => 'required|boolean']);
        $account = $request->user()->contasFinanceiras()->whereKey($id)->firstOrFail();
        $updated = $service->setNegativeBalancePermission($request->user(), $account, (bool) $data['allow_negative_balance']);

        return response()->json(['data' => $updated]);
    }

    public function payOpeningCardDebt(Request $request, string $id, CardInvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)->whereIn('type', ['bank', 'cash'])],
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'payment_date' => 'required|date|before_or_equal:today',
        ]);
        $movement = $service->payOpeningDebt($request->user(), $id, $data['account_id'], $data['payment_date'], (string) $data['amount']);

        return response()->json(['data' => $movement, 'message' => 'Pagamento da dívida inicial registado.']);
    }

    public function setOpeningBalance(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate([
            'opening_balance' => 'required|numeric|decimal:0,2|between:-9999999999.99,9999999999.99',
            'opening_balance_date' => 'required|date|before_or_equal:today',
            'allow_negative_balance' => 'sometimes|boolean',
        ]);

        $account = $request->user()->contasFinanceiras()->whereKey($id)->firstOrFail();
        $updated = $service->setOpeningBalance(
            $request->user(),
            $account,
            (string) $data['opening_balance'],
            $data['opening_balance_date'],
            (bool) ($data['allow_negative_balance'] ?? false)
        );

        return response()->json(['data' => $updated]);
    }

    public function setCardStatus(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        $card = $request->user()->contasFinanceiras()->whereKey($id)->where('type', 'credit_card')->firstOrFail();
        $updated = $service->setCardStatus($request->user(), $card, $data['status']);

        return response()->json(['data' => $updated, 'message' => $data['status'] === 'active' ? 'Cartão reativado.' : 'Cartão suspenso.']);
    }

    public function reopenCard(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $card = $request->user()->contasFinanceiras()->whereKey($id)->where('type', 'credit_card')->firstOrFail();

        return response()->json(['data' => $service->reopenCard($request->user(), $card), 'message' => 'Cartão reaberto.']);
    }

    public function deleteUnusedCard(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $card = $request->user()->contasFinanceiras()->whereKey($id)->where('type', 'credit_card')->firstOrFail();
        $service->deleteUnusedCard($request->user(), $card);

        return response()->json(['message' => 'Cartão eliminado.']);
    }

    public function closeAccount(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate(['transfer_to_account_id' => 'nullable|uuid']);
        $account = $request->user()->contasFinanceiras()->whereKey($id)->firstOrFail();
        $service->close($request->user(), $account, $data['transfer_to_account_id'] ?? null);

        return response()->json(['message' => 'Conta encerrada.']);
    }

    public function movements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'status' => ['nullable', Rule::in(['pending', 'partial', 'settled', 'void'])],
            'type' => ['nullable', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'account_id' => 'nullable|uuid',
        ]);

        $query = $request->user()->movimentos()->with(['contaOrigem:id,name,type', 'contaDestino:id,name,type', 'categoria:id,name'])
            ->where(fn ($builder) => $builder->whereNull('invoice_id')->orWhere('is_settlement_parent', false));
        if (! empty($data['month'])) {
            $query->whereBetween('movement_date', [
                $data['month'].'-01',
                Carbon::createFromFormat('!Y-m', $data['month'])->endOfMonth()->toDateString(),
            ]);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (! empty($data['account_id'])) {
            $query->where(fn ($q) => $q->where('source_account_id', $data['account_id'])
                ->orWhere('destination_account_id', $data['account_id']));
        }

        return response()->json($query->orderByDesc('movement_date')->paginate(30));
    }

    public function storeMovement(Request $request, FinancialMovementService $service, CardInvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'expense_behavior' => ['nullable', Rule::in(['fixed', 'variable'])],
            'status' => ['sometimes', Rule::in(['pending', 'settled'])],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date',
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'expense_behavior' => ['nullable', Rule::in(['fixed', 'variable'])],
            'adjustment_direction' => ['nullable', Rule::in(['increase', 'decrease'])],
        ]);

        if (($data['status'] ?? 'settled') === 'pending') {
            $movement = $service->createPending($request->user(), $data);
        } else {
            $account = $data['type'] === 'expense' && ! empty($data['source_account_id'])
                ? $request->user()->contasFinanceiras()->whereKey($data['source_account_id'])->first()
                : null;
            $movement = $account?->type === 'credit_card'
                ? $invoices->purchase($request->user(), [
                    'account_id' => $account->id,
                    'description' => $data['description'],
                    'amount' => $data['amount'],
                    'movement_date' => $data['movement_date'],
                    'category_id' => $data['category_id'] ?? null,
                    'expense_behavior' => $data['expense_behavior'] ?? 'variable',
                ])
                : $service->create($request->user(), $data);
        }

        return response()->json(['data' => $movement], 201);
    }

    public function settleMovement(Request $request, string $id, FinancialMovementService $service, CardInvoiceService $invoices, FinancialCommitmentService $commitments): JsonResponse
    {
        $data = $request->validate([
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
            'amount' => 'sometimes|required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'expected_amount' => 'sometimes|required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'sometimes|required|date',
        ]);

        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();

        return DB::transaction(function () use ($request, $movement, $data, $service, $invoices, $commitments): JsonResponse {
            if ($movement->debt_id || $movement->installment_id) {
                if (! $movement->debt_id || ! $movement->installment_id) {
                    throw ValidationException::withMessages(['movement' => 'Esta previsão tem uma ligação incompleta ao parcelamento.']);
                }
                $accountId = $data['source_account_id'] ?? $movement->source_account_id;
                if (! $accountId) {
                    throw ValidationException::withMessages(['source_account_id' => 'Seleciona uma conta para liquidar a prestação.']);
                }
                $debt = $request->user()->dividas()->whereKey($movement->debt_id)->firstOrFail();
                $installment = $debt->parcelas()->where('user_id', $request->user()->id)->whereKey($movement->installment_id)->firstOrFail();
                $result = $commitments->payInstallmentWithMovement($request->user(), $debt, $installment, [
                    'account_id' => $accountId,
                    'amount' => $data['amount'] ?? number_format(max(0, (float) $installment->amount - (float) $installment->paid_amount), 2, '.', ''),
                    'payment_date' => $data['movement_date'] ?? $movement->movement_date->toDateString(),
                    'expected_amount' => $data['expected_amount'] ?? null,
                ], $movement->id);

                return response()->json(['data' => $result['movement'], 'installment' => $result['installment']]);
            }

            // Valor e data são atributos do evento de liquidação; o original permanece intacto.
            if ($movement->type === 'expense') {
                $accountId = $data['source_account_id'] ?? $movement->source_account_id;
                $account = $accountId ? $request->user()->contasFinanceiras()->whereKey($accountId)->first() : null;
                if ($account?->type === 'credit_card') {
                    return response()->json(['data' => $invoices->settlePendingPurchase($request->user(), $movement, $account->id)]);
                }
            }

            return response()->json(['data' => $service->settle($request->user(), $movement, $data)]);
        });
    }

    public function confirmMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
        ]);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->confirmPending($request->user(), $movement, $data)]);
    }

    public function voidMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $service->voidPending($request->user(), $movement, $data['reason'] ?? null)]);
    }

    public function reverseMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        $service->deleteAsCorrection(
            $request->user(),
            $movement,
            $request->input('reason') ?: 'Movimento eliminado pelo utilizador.'
        );

        return response()->json(['ok' => true, 'message' => 'Movimento eliminado e saldos atualizados.']);
    }

    public function destroyRecurringOccurrence(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['only', 'this_and_future'])],
        ]);

        $user = $request->user();
        $movement = $user->movimentos()->whereKey($id)->firstOrFail();
        if (! $movement->recurrence_id) {
            return response()->json(['message' => 'Este movimento não pertence a uma recorrência.'], 422);
        }
        if ($data['scope'] === 'only' && $movement->status !== 'pending') {
            return response()->json(['message' => 'Este movimento já foi efetivado e será preservado no histórico financeiro. Podes interromper as ocorrências futuras.'], 422);
        }

        DB::transaction(function () use ($user, $movement, $data, $service): void {
            $rule = $user->recorrencias()->whereKey($movement->recurrence_id)->lockForUpdate()->firstOrFail();
            // Só previsões pendentes podem ser removidas por âmbito. Movimentos
            // já liquidados fazem parte do histórico e devem manter o seu
            // impacto financeiro; a regra é interrompida separadamente.
            $targets = $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'pending');

            if ($data['scope'] === 'only') {
                $targets->whereKey($movement->id);
            } elseif ($data['scope'] === 'this_and_future') {
                $rule->update(['is_active' => false]);
                $occurrenceDate = ($movement->recurrence_occurrence_date ?: $movement->movement_date)->toDateString();
                $targets->where(function ($query) use ($occurrenceDate) {
                    $query->whereDate('recurrence_occurrence_date', '>=', $occurrenceDate)
                        ->orWhere(function ($fallback) use ($occurrenceDate) {
                            $fallback->whereNull('recurrence_occurrence_date')
                                ->whereDate('movement_date', '>=', $occurrenceDate);
                        });
                });
            }

            // O estado pendente é o critério de exclusão. Um registo de auditoria
            // anterior não deve impedir a remoção de uma previsão ainda não liquidada.
            foreach ($targets->lockForUpdate()->get() as $target) {
                $service->deleteAsCorrection(
                    $user,
                    $target,
                    'Ocorrência removida pelo utilizador na gestão de movimentos.'
                );
            }
        });

        return response()->json(['ok' => true, 'message' => 'Movimento atualizado.']);
    }

    public function destroyMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        $service->deleteAsCorrection(
            $request->user(),
            $movement,
            'Movimento removido pelo utilizador como correção.'
        );

        return response()->json(['ok' => true, 'message' => 'Movimento removido e saldos atualizados.']);
    }

    public function updatePendingMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'description' => 'sometimes|required|string|max:255',
            'notes' => 'sometimes|nullable|string|max:2000',
            'amount' => 'sometimes|required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'sometimes|required|date',
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'expense_behavior' => ['sometimes', Rule::in(['fixed', 'variable'])],
        ]);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        $updated = DB::transaction(function () use ($request, $service, $movement, $data) {
            $updated = $service->updatePending($request->user(), $movement, $data);
            if ($updated->recurrence_id && $updated->legacy_type && $updated->legacy_id) {
                $table = $updated->legacy_type === 'despesa' ? 'despesas' : ($updated->legacy_type === 'receita' ? 'receitas' : null);
                if ($table) {
                    $dateColumn = $updated->legacy_type === 'despesa' ? 'expense_date' : 'income_date';
                    $slug = $updated->category_id
                        ? DB::table('categorias')->where('user_id', $request->user()->id)->where('id', $updated->category_id)->value('slug')
                        : null;
                    $allowed = $updated->type === 'expense'
                        ? ['casa', 'alimentacao', 'transportes', 'saude', 'lazer', 'compras', 'educacao', 'outros']
                        : ['salario', 'vendas', 'trabalho_extra', 'investimentos', 'reembolsos', 'outros'];
                    DB::table($table)->where('user_id', $request->user()->id)->where('id', $updated->legacy_id)->update([
                        'description' => $updated->description,
                        'amount' => $updated->amount,
                        'currency' => $request->user()->currency,
                        'category_id' => $updated->category_id,
                        'category' => in_array($slug, $allowed, true) ? $slug : 'outros',
                        $dateColumn => $updated->movement_date->toDateString(),
                    ]);
                }
            }

            return $updated;
        });

        return response()->json(['data' => $updated]);
    }
}
