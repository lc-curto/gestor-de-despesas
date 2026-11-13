<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaFinanceira extends Model
{
    use HasUuids;

    protected $table = 'contas_financeiras';

    protected $fillable = ['user_id',
        'name', 'type', 'currency', 'opening_balance', 'opening_balance_date',
        'is_operational', 'allow_negative_balance', 'status', 'closing_day', 'due_day', 'credit_limit',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_operational' => 'boolean',
            'allow_negative_balance' => 'boolean',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function movimentosOrigem(): HasMany { return $this->hasMany(Movimento::class, 'source_account_id'); }
    public function movimentosDestino(): HasMany { return $this->hasMany(Movimento::class, 'destination_account_id'); }
}
