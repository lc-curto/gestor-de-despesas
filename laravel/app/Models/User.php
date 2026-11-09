<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'password', 'currency'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * Define os valores padrão dos atributos do utilizador.
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    /**
 * Define a relação entre o utilizador e as suas despesas.
 */
    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class);
    }

    /**
 * Define a relação entre o utilizador e as suas receitas.
 */
    public function receitas(): HasMany
    {
        return $this->hasMany(Receita::class);
    }
}
