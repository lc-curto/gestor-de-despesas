<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $users = DB::table('users')->select('id', 'currency', 'financial_cutoff_date')->get();

            foreach ($users as $user) {
                $cutoff = $user->financial_cutoff_date ?? '2026-11-12';
                $legacyAccountId = (string) Str::uuid();

                DB::table('contas_financeiras')->insert([
                    'id' => $legacyAccountId,
                    'user_id' => $user->id,
                    'name' => 'Histórico anterior à migração',
                    'type' => 'legacy',
                    'currency' => $user->currency,
                    'opening_balance' => '0.00',
                    'opening_balance_date' => $cutoff,
                    'is_operational' => false,
                    'status' => 'historical',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('despesas')->where('user_id', $user->id)->orderBy('id')->chunk(200, function ($rows) use ($user, $cutoff, $legacyAccountId): void {
                    foreach ($rows as $row) {
                        $historical = $row->expense_date <= $cutoff;
                        DB::table('movimentos')->insertOrIgnore([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'type' => 'expense',
                            'status' => $historical ? 'settled' : 'pending',
                            'description' => $row->description,
                            'amount' => $row->amount,
                            'movement_date' => $row->expense_date,
                            'source_account_id' => $historical ? $legacyAccountId : null,
                            'destination_account_id' => null,
                            'category_id' => $row->category_id,
                            'is_historical' => $historical,
                            'requires_account_assignment' => !$historical,
                            'legacy_type' => 'despesa',
                            'legacy_id' => $row->id,
                            'settled_at' => $historical ? ($row->created_at ?? now()) : null,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => now(),
                        ]);
                    }
                });

                DB::table('receitas')->where('user_id', $user->id)->orderBy('id')->chunk(200, function ($rows) use ($user, $cutoff, $legacyAccountId): void {
                    foreach ($rows as $row) {
                        $historical = $row->income_date <= $cutoff;
                        DB::table('movimentos')->insertOrIgnore([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'type' => 'income',
                            'status' => $historical ? 'settled' : 'pending',
                            'description' => $row->description,
                            'amount' => $row->amount,
                            'movement_date' => $row->income_date,
                            'source_account_id' => null,
                            'destination_account_id' => $historical ? $legacyAccountId : null,
                            'category_id' => $row->category_id,
                            'is_historical' => $historical,
                            'requires_account_assignment' => !$historical,
                            'legacy_type' => 'receita',
                            'legacy_id' => $row->id,
                            'settled_at' => $historical ? ($row->created_at ?? now()) : null,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('movimentos')
                ->whereIn('legacy_type', ['despesa', 'receita'])
                ->delete();

            DB::table('contas_financeiras')
                ->where('type', 'legacy')
                ->delete();
        });
    }
};
