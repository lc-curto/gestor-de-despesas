<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fatura extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'faturas';

    protected $fillable = ['user_id', 'account_id', 'reference_month', 'closing_date', 'due_date', 'status', 'statement_amount', 'closed_at'];

    protected function casts(): array
    {
        return ['reference_month' => 'date', 'closing_date' => 'date', 'due_date' => 'date', 'statement_amount' => 'decimal:2', 'closed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'account_id');
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(Movimento::class, 'invoice_id');
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Movimento::class, 'invoice_id')->where('type', 'transfer');
    }

    public function creditos(): HasMany
    {
        return $this->hasMany(Movimento::class, 'invoice_id')->where('invoice_charge_type', 'credit');
    }

    public function saldosTransportados(): HasMany
    {
        return $this->hasMany(FaturaSaldoTransportado::class, 'target_invoice_id');
    }

    public function saldoTransportadoPara(): HasMany
    {
        return $this->hasMany(FaturaSaldoTransportado::class, 'source_invoice_id');
    }
}
