<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelaDivida extends Model
{
    use HasUuids;
    protected $table = 'parcelas_divida';
    protected $fillable = ['debt_id', 'installment_number', 'due_date', 'amount', 'principal_amount', 'interest_amount', 'paid_amount', 'status'];
    protected function casts(): array { return ['due_date' => 'date', 'amount' => 'decimal:2', 'principal_amount' => 'decimal:2', 'interest_amount' => 'decimal:2', 'paid_amount' => 'decimal:2']; }
    public function divida(): BelongsTo { return $this->belongsTo(Divida::class, 'debt_id'); }
}
