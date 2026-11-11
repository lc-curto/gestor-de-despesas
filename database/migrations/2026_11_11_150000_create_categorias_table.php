<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->string('type', 10);
            $table->string('icon', 60)->default('bi-tag');
            $table->string('color', 7)->default('#635bff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'type', 'slug'], 'categorias_user_type_slug_unique');
            $table->unique(['user_id', 'type', 'name'], 'categorias_user_type_name_unique');
            $table->index(['user_id', 'type', 'is_active'], 'categorias_user_type_active_index');
        });

        $defaults = [
            'despesa' => [
                'casa' => ['Casa', 'bi-house', '#D97706'],
                'alimentacao' => ['Alimentação', 'bi-cup-hot', '#16A085'],
                'transportes' => ['Transporte', 'bi-car-front', '#2563EB'],
                'saude' => ['Saúde', 'bi-heart-pulse', '#DC2626'],
                'lazer' => ['Lazer', 'bi-controller', '#9333EA'],
                'compras' => ['Compras', 'bi-bag', '#D97706'],
                'educacao' => ['Educação', 'bi-mortarboard', '#0891B2'],
                'outros' => ['Outros', 'bi-three-dots', '#64748B'],
            ],
            'receita' => [
                'salario' => ['Salário', 'bi-wallet2', '#16A34A'],
                'vendas' => ['Vendas', 'bi-shop', '#2563EB'],
                'trabalho_extra' => ['Trabalho extra', 'bi-briefcase', '#0891B2'],
                'investimentos' => ['Investimentos', 'bi-graph-up-arrow', '#9333EA'],
                'reembolsos' => ['Reembolsos', 'bi-arrow-counterclockwise', '#D97706'],
                'outros' => ['Outros', 'bi-three-dots', '#64748B'],
            ],
        ];

        foreach (DB::table('users')->select('id')->orderBy('id')->get() as $user) {
            foreach ($defaults as $type => $items) {
                foreach ($items as $slug => [$name, $icon, $color]) {
                    DB::table('categorias')->insert([
                        'id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'name' => $name,
                        'slug' => $slug,
                        'type' => $type,
                        'icon' => $icon,
                        'color' => $color,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        Schema::table('despesas', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->after('category')
                ->constrained('categorias')->restrictOnDelete();
        });
        Schema::table('receitas', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->after('category')
                ->constrained('categorias')->restrictOnDelete();
        });

        foreach (DB::table('despesas')->select('id', 'user_id', 'category')->get() as $movement) {
            $categoryId = DB::table('categorias')
                ->where('user_id', $movement->user_id)
                ->where('type', 'despesa')
                ->where('slug', $movement->category)
                ->value('id') ?? DB::table('categorias')->where('user_id', $movement->user_id)->where('type', 'despesa')->where('slug', 'outros')->value('id');
            if ($categoryId) {
                DB::table('despesas')->where('id', $movement->id)->update(['category_id' => $categoryId]);
            }
        }

        foreach (DB::table('receitas')->select('id', 'user_id', 'category')->get() as $movement) {
            $categoryId = DB::table('categorias')
                ->where('user_id', $movement->user_id)
                ->where('type', 'receita')
                ->where('slug', $movement->category)
                ->value('id') ?? DB::table('categorias')->where('user_id', $movement->user_id)->where('type', 'receita')->where('slug', 'outros')->value('id');
            if ($categoryId) {
                DB::table('receitas')->where('id', $movement->id)->update(['category_id' => $categoryId]);
            }
        }

        if (DB::table('despesas')->whereNull('category_id')->exists()
            || DB::table('receitas')->whereNull('category_id')->exists()) {
            throw new RuntimeException('Existem movimentos sem categoria correspondente; a migração foi interrompida para preservar os dados.');
        }

    }

    public function down(): void
    {
        Schema::table('receitas', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
        Schema::dropIfExists('categorias');
    }
};
