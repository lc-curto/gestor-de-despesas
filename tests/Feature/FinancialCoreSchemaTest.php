<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialCoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(string $userId, string $type = 'bank', bool $allowNegative = false): string
    {
        $id = (string) Str::uuid();

        DB::table('contas_financeiras')->insert([
            'id' => $id,
            'user_id' => $userId,
            'name' => 'Conta de teste',
            'type' => $type,
            'currency' => 'EUR',
            'opening_balance' => '0.00',
            'is_operational' => true,
            'allow_negative_balance' => $allowNegative,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_financial_foreign_keys_prevent_cross_user_account_references(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignAccountId = $this->createAccount($owner->id);

        $this->expectException(QueryException::class);

        DB::table('movimentos')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'type' => 'expense',
            'status' => 'settled',
            'description' => 'Referência cruzada inválida',
            'amount' => '10.00',
            'movement_date' => now()->toDateString(),
            'source_account_id' => $foreignAccountId,
            'is_historical' => false,
            'requires_account_assignment' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_transfer_must_use_two_different_accounts(): void
    {
        $user = User::factory()->create();
        $accountId = $this->createAccount($user->id);

        $this->expectException(QueryException::class);

        DB::table('movimentos')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'transfer',
            'status' => 'settled',
            'description' => 'Transferência inválida',
            'amount' => '10.00',
            'movement_date' => now()->toDateString(),
            'source_account_id' => $accountId,
            'destination_account_id' => $accountId,
            'is_historical' => false,
            'requires_account_assignment' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_negative_opening_balance_is_allowed_without_extra_confirmation(): void
    {
        $user = User::factory()->create();

        DB::table('contas_financeiras')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Saldo negativo não autorizado',
            'type' => 'bank',
            'currency' => 'EUR',
            'opening_balance' => '-25.00',
            'is_operational' => true,
            'allow_negative_balance' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('-25.00', DB::table('contas_financeiras')->where('user_id', $user->id)->value('opening_balance'));
    }

    public function test_negative_opening_balance_is_allowed_when_user_enables_it(): void
    {
        $user = User::factory()->create();

        $id = $this->createAccount($user->id, 'bank', true);

        DB::table('contas_financeiras')->where('id', $id)->update([
            'opening_balance' => '-25.00',
        ]);

        $this->assertSame('-25.00', DB::table('contas_financeiras')->where('id', $id)->value('opening_balance'));
    }
}
