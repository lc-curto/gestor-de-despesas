<?php

namespace App\\Http\\Controllers;

use App\\Support\\Categorias;
use Illuminate\\Http\\Request;

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
            $tipo === 'despesa' ? Categorias::DESPESAS : Categorias::RECEITAS,
        ];
    }

    private function query(Request $request)
    {
        [, $relation] = $this->config($request);
        return $request->user()->{$relation}();
    }

    private function rules(Request $request): array
    {
        [, , $dateField, $categories] = $this->config($request);

        return [
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999.99',
            'category' => 'required|in:' . implode(',', array_keys($categories)),
            $dateField => 'required|date',
        ];
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate($this->rules($request));
        // A moeda é uma propriedade da conta, nunca um campo editável do movimento.
        $data['currency'] = $request->user()->currency;

        return $data;
    }

    public function index(Request $request)
    {
        [$tipo, , $dateField, $categories] = $this->config($request);
        $filters = $request->validate([
            'categoria' => 'nullable|string',
            'inicio' => 'nullable|date',
            'fim' => 'nullable|date|after_or_equal:inicio',
        ]);
        $query = $this->query($request)->orderByDesc($dateField)->orderBy('id');
        if (!empty($filters['categoria']) && array_key_exists($filters['categoria'], $categories)) {
            $query->where('category', $filters['categoria']);
        }
        if (!empty($filters['inicio'])) $query->whereDate($dateField, '>=', $filters['inicio']);
        if (!empty($filters['fim'])) $query->whereDate($dateField, '<=', $filters['fim']);

        $registos = $query->paginate(10)->withQueryString();
        return view('finance.index', compact('tipo', 'dateField', 'categories', 'registos'));
    }

    public function create(Request $request)
    {
        [$tipo, , $dateField, $categories] = $this->config($request);
        $registo = null;
        return view('finance.form', compact('tipo', 'dateField', 'categories', 'registo'));
    }

    public function store(Request $request)
    {
        [$tipo] = $this->config($request);
        $this->query($request)->create($this->validatedData($request));

        if ($request->boolean('return_to_dashboard')) {
            return redirect()->route('dashboard')->with('success', 'Registo criado.');
        }

        return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
            ->with('success', 'Registo criado.');
    }

    public function edit(Request $request, string $id)
    {
        [$tipo, , $dateField, $categories] = $this->config($request);
        $registo = $this->query($request)->findOrFail($id);
        return view('finance.form', compact('tipo', 'dateField', 'categories', 'registo'));
    }

    public function update(Request $request, string $id)
    {
        [$tipo] = $this->config($request);
        $this->query($request)->findOrFail($id)->update($this->validatedData($request));

        if ($request->boolean('return_to_dashboard')) {
            return redirect()->route('dashboard')->with('success', 'Registo atualizado.');
        }

        return redirect()->route($tipo === 'despesa' ? 'despesas.index' : 'receitas.index')
            ->with('success', 'Registo atualizado.');
    }

    public function destroy(Request $request, string $id)
    {
        $this->query($request)->findOrFail($id)->delete();
        return back()->with('success', 'Registo eliminado.');
    }
}
