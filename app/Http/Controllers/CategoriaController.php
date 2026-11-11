<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        $categorias = $request->user()->categorias()
            ->withCount(['despesas', 'receitas'])
            ->orderBy('type')->orderBy('name')->get();

        $total = $categorias->count();
        $totalDespesas = $categorias->where('type', 'despesa')->count();
        $totalReceitas = $categorias->where('type', 'receita')->count();

        return view('categorias.index', compact('categorias', 'total', 'totalDespesas', 'totalReceitas'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;
        $data['slug'] = $this->uniqueSlug($request->user()->id, $data['type'], $data['name']);

        $request->user()->categorias()->create($data);

        return redirect()->route('categorias.index')->with('success', 'Categoria criada com sucesso.');
    }

    public function update(Request $request, string $id)
    {
        $categoria = $request->user()->categorias()->findOrFail($id);
        $data = $this->validated($request, $categoria);
        $data['slug'] = $this->uniqueSlug($request->user()->id, $data['type'], $data['name'], $categoria->id);
        $categoria->update($data);

        return redirect()->route('categorias.index')->with('success', 'Categoria atualizada com sucesso.');
    }

    public function toggle(Request $request, string $id)
    {
        $categoria = $request->user()->categorias()->findOrFail($id);
        $categoria->update(['is_active' => !$categoria->is_active]);

        return redirect()->route('categorias.index')->with('success', $categoria->is_active
            ? 'Categoria ativada.' : 'Categoria desativada. Os movimentos existentes foram preservados.');
    }

    public function destroy(Request $request, string $id)
    {
        $categoria = $request->user()->categorias()->findOrFail($id);
        $count = $categoria->type === 'despesa'
            ? $categoria->despesas()->count()
            : $categoria->receitas()->count();

        if ($count > 0) {
            return back()->withErrors(['categoria' => 'Esta categoria tem movimentos associados. Desative-a para preservar o histórico.']);
        }

        $categoria->delete();

        return redirect()->route('categorias.index')->with('success', 'Categoria eliminada.');
    }

    private function validated(Request $request, ?Categoria $categoria = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categorias', 'name')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $request->input('type'))
                    ->ignore($categoria?->id),
            ],
            'type' => ['required', Rule::in(['despesa', 'receita'])],
            'icon' => ['required', 'string', 'max:60', 'regex:/^bi-[a-z0-9-]+$/'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.unique' => 'Já existe uma categoria com este nome e tipo.',
            'icon.regex' => 'Seleciona um ícone válido.',
            'color.regex' => 'Seleciona uma cor hexadecimal válida.',
        ]);
    }

    private function uniqueSlug(string $userId, string $type, string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $suffix = 2;

        while (Categoria::query()->where('user_id', $userId)->where('type', $type)
            ->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
