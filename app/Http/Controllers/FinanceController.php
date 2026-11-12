<?php

namespace App\Http\Controllers;

use App\Services\Financial\FinancialMovementService;
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
        $types = $tipo === 'despesa' ? ['bank', 'cash', 'credit_card'] : ['bank', 'cash'];

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

        $data = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'account_id' => [
                'required', 'uuid',
                Rule::exists('contas_financeiras', 'id')->where('user_id', $request->user()->id),
            ],
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
        ]);

        $account = $request->user()->contasFinanceiras()
            ->whereKey($data['account_id'])
            ->whereIn('type', $tipo === 'despesa' ? ['bank', 'cash', 'credit_card'] : ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->first();
        if (!$account || $account->currency !== $request->user()->currency) {
            throw ValidationException::withMessages(['account_id' => $tipo === 'despesa'
                ? 'Escolhe uma conta bancária, de dinheiro ou cartão ativa, configurada e na moeda da tua conta.'
                : 'Escolhe uma conta bancária ou de dinheiro ativa, configurada e na moeda da tua conta.']);
        }

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
            ->with(['contaOrigem', 'contaDestino'])
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

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo', 'accounts', 'financialMovement'));
    }

    public function store(Request $request, FinancialMovementService $service)
    {
        [$tipo] = $this->config($request);
        $data = $this->validatedData($request);
        $accountId = $data['account_id'];
        unset($data['account_id']);
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';
        $dateField = $tipo === 'despesa' ? 'expense_date' : 'income_date';

        DB::transaction(function () use ($request, $service, $tipo, $data, $accountId, $legacyType, $dateField): void {
            $legacy = $this->query($request)->create($data);
            $payload = $this->canonicalPayload($tipo, array_merge($data, ['account_id' => $accountId]), $legacyType, $legacy->id);
            $service->create($request->user(), $payload);
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

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo', 'accounts', 'financialMovement'));
    }

    public function update(Request $request, string $id, FinancialMovementService $service)
    {
        [$tipo, , $dateField] = $this->config($request);
        $data = $this->validatedData($request, $id);
        $accountId = $data['account_id'];
        unset($data['account_id']);
        $legacyType = $tipo === 'despesa' ? 'despesa' : 'receita';
        $date = $data[$dateField];

        DB::transaction(function () use ($request, $service, $tipo, $id, $data, $accountId, $legacyType, $dateField, $date): void {
            $legacy = $this->query($request)->whereKey($id)->lockForUpdate()->firstOrFail();
            $movement = $this->linkedMovement($request, $tipo, $legacy->id);
            $payload = $this->canonicalPayload($tipo, array_merge($data, ['account_id' => $accountId]), $legacyType, $legacy->id);

            if (!$movement) {
                $cutoff = $request->user()->financial_cutoff_date?->toDateString();
                $payload['is_historical'] = $cutoff !== null && $date <= $cutoff;
                $service->create($request->user(), $payload);
            } elseif ($movement->status === 'pending') {
                $movement = $service->updatePending($request->user(), $movement, [
                    'description' => $payload['description'],
                    'amount' => $payload['amount'],
                    'movement_date' => $payload['movement_date'],
                    'category_id' => $payload['category_id'],
                ]);
                $service->settle($request->user(), $movement, [
                    ($tipo === 'despesa' ? 'source_account_id' : 'destination_account_id') => $accountId,
                ]);
            } else {
                // Sem estorno: a edição substitui o movimento canónico numa só transação.
                // Assim valor, data, conta e categoria ficam sempre sincronizados.
                $cutoff = $request->user()->financial_cutoff_date?->toDateString();
                $payload['is_historical'] = (bool) $movement->is_historical || ($cutoff !== null && $date <= $cutoff);
                $movement->historico()->delete();
                $movement->delete();
                $service->create($request->user(), $payload);
            }

            $legacy->update($data);
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo atualizado.']);
        }
        if ($request->boolean('return_to_dashboard')) {
            return redirect()->route('dashboard')->with('success', 'Registo atualizado.');
        }

        return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
            ->with('success', 'Registo atualizado.');
    }

    public function destroy(Request $request, string $id)
    {
        [$tipo] = $this->config($request);
        DB::transaction(function () use ($request, $tipo, $id): void {
            $legacy = $this->query($request)->whereKey($id)->lockForUpdate()->firstOrFail();
            $movement = $this->linkedMovement($request, $tipo, $legacy->id);
            if ($movement) {
                // Exclusão direta: remove o histórico técnico antes da FK restritiva.
                $movement->historico()->delete();
                $movement->delete();
            }
            $legacy->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo eliminado.']);
        }

        return back()->with('success', 'Registo eliminado.');
    }

}
