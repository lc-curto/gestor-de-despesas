<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Divida extends Model
{
    use HasUuids;
    protected $table = 'dividas';
    protected $fillable = ['user_id','name', 'type', 'account_id', 'total_amount', 'start_date', 'due_date', 'status'];
    protected function casts(): array { return ['total_amount' => 'decimal:2', 'start_date' => 'date', 'due_date' => 'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function conta(): BelongsTo { return $this->belongsTo(ContaFinanceira::class, 'account_id'); }
    public function parcelas(): HasMany { return $this->hasMany(ParcelaDivida::class, 'debt_id'); }
    public function movimentos(): HasMany { return $this->hasMany(Movimento::class, 'debt_id'); }
}
