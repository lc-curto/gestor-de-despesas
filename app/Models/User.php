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

    protected $attributes = ['currency' => 'EUR'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class);
    }

    public function receitas(): HasMany
    {
        return $this->hasMany(Receita::class);
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            // Remove primeiro os movimentos para respeitar a FK restritiva da categoria.
            $user->despesas()->delete();
            $user->receitas()->delete();
            $user->categorias()->delete();
        });

        static::created(function (User $user): void {
            $defaults = [
                'despesa' => [
                    ['Casa', 'casa', 'house', '#D97706'],
                    ['Alimentação', 'alimentacao', 'utensils', '#16A085'],
                    ['Transporte', 'transportes', 'car-front', '#2563EB'],
                    ['Saúde', 'saude', 'heart-pulse', '#DC2626'],
                    ['Lazer', 'lazer', 'gamepad-2', '#9333EA'],
                    ['Compras', 'compras', 'shopping-bag', '#D97706'],
                    ['Educação', 'educacao', 'graduation-cap', '#0891B2'],
                    ['Outros', 'outros', 'ellipsis', '#64748B'],
                ],
                'receita' => [
                    ['Salário', 'salario', 'wallet', '#16A34A'],
                    ['Vendas', 'vendas', 'store', '#2563EB'],
                    ['Trabalho extra', 'trabalho_extra', 'briefcase-business', '#0891B2'],
                    ['Investimentos', 'investimentos', 'chart-no-axes-combined', '#9333EA'],
                    ['Reembolsos', 'reembolsos', 'undo-2', '#D97706'],
                    ['Outros', 'outros', 'ellipsis', '#64748B'],
                ],
            ];

            foreach ($defaults as $type => $items) {
                foreach ($items as [$name, $slug, $icon, $color]) {
                    $user->categorias()->create(compact('name', 'slug', 'icon', 'color', 'type') + ['is_active' => true]);
                }
            }
        });
    }
}
