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
        static::created(function (User $user): void {
            $defaults = [
                'despesa' => [
                    ['Casa', 'casa', 'bi-house', '#D97706'],
                    ['Alimentação', 'alimentacao', 'bi-cup-hot', '#16A085'],
                    ['Transporte', 'transportes', 'bi-car-front', '#2563EB'],
                    ['Saúde', 'saude', 'bi-heart-pulse', '#DC2626'],
                    ['Lazer', 'lazer', 'bi-controller', '#9333EA'],
                    ['Compras', 'compras', 'bi-bag', '#D97706'],
                    ['Educação', 'educacao', 'bi-mortarboard', '#0891B2'],
                    ['Outros', 'outros', 'bi-three-dots', '#64748B'],
                ],
                'receita' => [
                    ['Salário', 'salario', 'bi-wallet2', '#16A34A'],
                    ['Vendas', 'vendas', 'bi-shop', '#2563EB'],
                    ['Trabalho extra', 'trabalho_extra', 'bi-briefcase', '#0891B2'],
                    ['Investimentos', 'investimentos', 'bi-graph-up-arrow', '#9333EA'],
                    ['Reembolsos', 'reembolsos', 'bi-arrow-counterclockwise', '#D97706'],
                    ['Outros', 'outros', 'bi-three-dots', '#64748B'],
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
