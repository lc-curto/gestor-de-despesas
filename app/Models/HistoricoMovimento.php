<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricoMovimento extends Model
{
    use HasUuids;

    protected $table = 'historico_movimentos';
    public $timestamps = false;
    protected $fillable = ['user_id', 'movement_id', 'changed_by', 'action', 'before_data', 'after_data', 'reason'];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];
    }

    public function movimento(): BelongsTo { return $this->belongsTo(Movimento::class, 'movement_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
