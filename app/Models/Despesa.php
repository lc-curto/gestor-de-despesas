<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'description', 'amount', 'currency', 'category', 'category_id', 'expense_date'])]
class Despesa extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $attributes = ['currency' => 'EUR'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'category_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $movement): void {
            // Compatibilidade com integrações e testes que ainda enviam a chave textual antiga.
            if ($movement->category_id || !$movement->user_id || !$movement->category) {
                return;
            }

            $movement->category_id = Categoria::query()
                ->where('user_id', $movement->user_id)
                ->where('type', 'despesa')
                ->where('slug', $movement->category)
                ->value('id');
        });
    }
}
