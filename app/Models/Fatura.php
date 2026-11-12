<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fatura extends Model
{
    use HasUuids;
    protected $table = 'faturas';
    protected $fillable = ['account_id', 'reference_month', 'closing_date', 'due_date', 'status'];
    protected function casts(): array { return ['reference_month' => 'date', 'closing_date' => 'date', 'due_date' => 'date']; }
    public function conta(): BelongsTo { return $this->belongsTo(ContaFinanceira::class, 'account_id'); }
    public function movimentos(): HasMany { return $this->hasMany(Movimento::class, 'invoice_id'); }
}
