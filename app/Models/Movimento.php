<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movimento extends Model
{
    use HasUuids;

    protected $table = 'movimentos';

    protected $fillable = ['user_id',
        'type', 'status', 'description', 'amount', 'movement_date',
        'source_account_id', 'destination_account_id', 'category_id',
        'invoice_id', 'recurrence_id', 'debt_id', 'installment_id',
        'adjustment_direction', 'is_historical', 'requires_account_assignment', 'legacy_type', 'legacy_id', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'movement_date' => 'date',
            'settled_at' => 'datetime',
            'is_historical' => 'boolean',
            'requires_account_assignment' => 'boolean',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function contaOrigem(): BelongsTo { return $this->belongsTo(ContaFinanceira::class, 'source_account_id'); }
    public function contaDestino(): BelongsTo { return $this->belongsTo(ContaFinanceira::class, 'destination_account_id'); }
    public function categoria(): BelongsTo { return $this->belongsTo(Categoria::class, 'category_id'); }
    public function historico(): HasMany { return $this->hasMany(HistoricoMovimento::class, 'movement_id'); }
}
