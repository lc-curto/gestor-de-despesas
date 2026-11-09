<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'description',
    'amount',
    'currency',
    'category',
    'expense_date',
])]
class Despesa extends Model
{
    use HasUuids;
    public $timestamps = false;

    /**
     * Define os valores padrão da despesa.
     */
    protected $attributes = [
        'currency' => 'EUR',
    ];

    /**
     * Define os atributos convertidos automaticamente.
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Define a relação entre a despesa e o seu utilizador.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
