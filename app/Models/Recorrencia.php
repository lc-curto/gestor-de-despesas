<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recorrencia extends Model
{
    use HasUuids;
    protected $table = 'recorrencias';
    protected $fillable = ['type', 'description', 'amount', 'category_id', 'account_id', 'frequency', 'interval_value', 'next_occurrence', 'ends_at', 'is_active'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'next_occurrence' => 'date', 'ends_at' => 'date', 'is_active' => 'boolean']; }
    public function conta(): BelongsTo { return $this->belongsTo(ContaFinanceira::class, 'account_id'); }
    public function movimentos(): HasMany { return $this->hasMany(Movimento::class, 'recurrence_id'); }
}
