<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'slug', 'type', 'icon', 'color', 'is_active'])]
class Categoria extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class, 'category_id');
    }

    public function receitas(): HasMany
    {
        return $this->hasMany(Receita::class, 'category_id');
    }

    public function movimentosCount(): int
    {
        return $this->type === 'despesa' ? $this->despesas()->count() : $this->receitas()->count();
    }
}
