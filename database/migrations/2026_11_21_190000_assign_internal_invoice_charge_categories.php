<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'interest' => ['Juros', 'juros-fatura'],
            'late_fee' => ['Multas', 'multa-fatura'],
            'commission' => ['Comissões', 'comissao-fatura'],
            'tax' => ['Impostos', 'imposto-fatura'],
            'other' => ['Outros encargos', 'outro-encargo-fatura'],
        ];

        foreach ($definitions as $chargeType => [$name, $slug]) {
            $userIds = DB::table('movimentos')->where('invoice_charge_type', $chargeType)
                ->distinct()->pluck('user_id');

            foreach ($userIds as $userId) {
                $category = DB::table('categorias')->where('user_id', $userId)
                    ->where('type', 'despesa')->where('slug', $slug)->first();

                if (! $category) {
                    $categoryId = (string) Str::uuid();
                    DB::table('categorias')->insert([
                        'id' => $categoryId, 'user_id' => $userId, 'name' => $name,
                        'slug' => $slug, 'type' => 'despesa', 'icon' => 'receipt',
                        'color' => '#64748B', 'is_active' => true, 'is_system' => true,
                        'show_in_management' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $categoryId = $category->id;
                    DB::table('categorias')->where('id', $categoryId)->update([
                        'is_system' => true, 'show_in_management' => false, 'updated_at' => now(),
                    ]);
                }

                // Corrige apenas a categoria; preserva IDs, valores e histórico do movimento.
                DB::table('movimentos')->where('user_id', $userId)
                    ->where('invoice_charge_type', $chargeType)
                    ->update(['category_id' => $categoryId, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Não reverte associações financeiras nem elimina categorias já utilizadas.
    }
};
