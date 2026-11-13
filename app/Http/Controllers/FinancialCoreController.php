<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\FinancialCommitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

class FinancialCoreController extends Controller
{
    public function storeCommitment(Request $request, FinancialCommitmentService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'total_amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'start_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'payment_condition' => ['required', Rule::in(['cash', 'deferred', 'installments'])],
            'payment_method' => 'nullable|string|max:30',
            'installments_count' => 'nullable|integer|min:1|max:120',
            'account_id' => ['nullable', 'uuid', Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id)],
        ]);
        return response()->json(['data' => $service->create($request->user(), $data)], 201);
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
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(['bank', 'cash', 'credit_card'])],
            'opening_balance' => 'nullable|numeric|decimal:0,2|between:-9999999999.99,9999999999.99',
            'opening_balance_date' => 'required_with:opening_balance|date|before_or_equal:today',
            'allow_negative_balance' => 'sometimes|boolean',
        ]);

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
                Carbon::createFromFormat('!Y-m', $data['month'])->endOfMonth()->toDateString(),
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
            'status' => ['sometimes', Rule::in(['pending', 'settled'])],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'movement_date' => 'required|date',
            'source_account_id' => 'nullable|uuid',
            'destination_account_id' => 'nullable|uuid',
            'category_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('user_id', $request->user()->id)],
            'adjustment_direction' => ['nullable', Rule::in(['increase', 'decrease'])],
        ]);

        $movement = ($data['status'] ?? 'settled') === 'pending'
            ? $service->createPending($request->user(), $data)
            : $service->create($request->user(), $data);
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

    public function reverseMovement(Request $request, string $id, FinancialMovementService $service): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        return response()->json(['data' => $service->reverse($request->user(), $movement, $data['reason'] ?? null)]);
    }

    public function destroyMovement(Request $request, string $id): JsonResponse
    {
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        if ($movement->status === 'settled') {
            return response()->json(['message' => 'Movimentos liquidados devem ser estornados, não eliminados.'], 422);
        }

        DB::transaction(function () use ($movement): void {
            $movement->historico()->delete();
            $movement->delete();
        });

        return response()->json(['ok' => true, 'message' => 'Movimento eliminado.']);
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
