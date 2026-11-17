<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaturaSaldoTransportado extends Model
{
    use HasUuids;

    protected $table = 'fatura_saldos_transportados';

    protected $fillable = ['user_id', 'source_invoice_id', 'target_invoice_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Fatura::class, 'source_invoice_id');
    }

    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(Fatura::class, 'target_invoice_id');
    }
}
