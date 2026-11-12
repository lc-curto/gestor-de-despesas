<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialCoreController extends Controller
{
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
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(['bank', 'cash', 'credit_card'])],
            'opening_balance' => 'nullable|numeric|decimal:0,2|between:-9999999999.99,9999999999.99',
            'opening_balance_date' => 'required_with:opening_balance|date',
            'allow_negative_balance' => 'sometimes|boolean',
        ]);

        $account = $service->create($request->user(), $data);
        return response()->json(['data' => $account], 201);
    }

    public function setOpeningBalance(Request $request, string $id, FinancialAccountService $service): JsonResponse
    {
        $data = $request->validate([
            'opening_balance' => 'required|numeric|decimal:0,2|between:-9999999999.99,9999999999.99',
            'opening_balance_date' => 'required|date',
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
            'status' => ['nullable', Rule::in(['pending', 'settled', 'void'])],
            'type' => ['nullable', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'account_id' => 'nullable|uuid',
        ]);

        $query = $request->user()->movimentos()->with(['contaOrigem:id,name,type', 'contaDestino:id,name,type', 'categoria:id,name']);
        if (!empty($data['month'])) {
            $query->whereBetween('movement_date', [
                $data['month'] . '-01',
                now()->parse($data['month'] . '-01')->endOfMonth()->toDateString(),
            ]);
        }
        if (!empty($data['status'])) $query->where('status', $data['status']);
        if (!empty($data['type'])) $query->where('type', $data['type']);
        if (!empty($data['account_id'])) {
            $query->where(fn ($q) => $q->where('source_account_id', $data['account_id'])
                ->orWhere('destination_account_id', $data['account_id']));
        }

        return response()->json($query->orderByDesc('movement_date')->paginate(30));
    }

    public function storeMovement(Request $request, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date',
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'adjustment_direction' => ['nullable', Rule::in(['increase', 'decrease'])],
        ]);

        $movement = $service->create($request->user(), $data);
        return response()->json(['data' => $movement], 201);
    }

    public function settleMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
        ]);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        return response()->json(['data' => $service->settle($request->user(), $movement, $data)]);
    }

    public function voidMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        return response()->json(['data' => $service->voidPending($request->user(), $movement, $data['reason'] ?? null)]);
    }

    public function updatePendingMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate([
            'description' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'sometimes|required|date',
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
        ]);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        return response()->json(['data' => $service->updatePending($request->user(), $movement, $data)]);
    }
}
