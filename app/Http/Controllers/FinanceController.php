<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        [$tipo, $relation, $dateField] = $this->config($request);

        $rules = [
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'category_id' => [
                'required',
                'uuid',
                Rule::exists('categorias', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $tipo),
            ],
            $dateField => 'required|date',
        ];

        $data = $request->validate($rules);
        $category = $request->user()->categorias()->where('type', $tipo)
            ->whereKey($data['category_id'])->firstOrFail();

        if (!$category->is_active) {
            $current = $movementId
                ? $this->query($request)->whereKey($movementId)->value('category_id')
                : null;

            if ($current !== $category->id) {
                abort(422, 'Esta categoria está inativa e não pode ser utilizada em novos movimentos.');
            }
        }

        // Mantém a coluna textual antiga compatível com o CHECK legado.
        // category_id passa a ser a referência canónica da categoria.
        $legacyKeys = $tipo === 'despesa'
            ? ['casa', 'alimentacao', 'transportes', 'saude', 'lazer', 'compras', 'educacao', 'outros']
            : ['salario', 'vendas', 'trabalho_extra', 'investimentos', 'reembolsos', 'outros'];

        return array_merge($data, [
            'category' => in_array($category->slug, $legacyKeys, true) ? $category->slug : 'outros',
            'currency' => $request->user()->currency,
        ]);
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
        $data = $dateField;

        return view('finance.index', compact('tipo', 'data', 'cats', 'registos'));
    }

    public function create(Request $request)
    {
        [$tipo, , $dateField] = $this->config($request);
        $registo = null;
        $data = $dateField;
        $cats = $this->categoryOptions($request, $tipo);

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo'));
    }

    public function store(Request $request)
    {
        [$tipo] = $this->config($request);
        $this->query($request)->create($this->validatedData($request));

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
        $data = $dateField;
        $cats = $this->categoryOptions($request, $tipo, true);

        return view('finance.form', compact('tipo', 'data', 'cats', 'registo'));
    }

    public function update(Request $request, string $id)
    {
        [$tipo] = $this->config($request);
        $movement = $this->query($request)->findOrFail($id);
        $movement->update($this->validatedData($request, $id));

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
        $this->query($request)->findOrFail($id)->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Registo eliminado.']);
        }

        return back()->with('success', 'Registo eliminado.');
    }
}
