<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialDeletionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_mysql_sessions_cannot_spend_the_same_available_balance(): void
    {
        $primaryName = config('database.default');
        $primary = DB::connection($primaryName);

        if ($primary->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Este teste exige MySQL/InnoDB e duas ligações independentes.');
        }

        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta concorrente',
            'type' => 'bank',
            'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $fixturesCommitted = false;
        $secondary = null;

        try {
            $primary->commit();
            $fixturesCommitted = true;

            config(['database.connections.concurrent' => config('database.connections.'.$primaryName)]);
            DB::purge('concurrent');
            $secondary = DB::connection('concurrent');
            $this->assertNotSame($primary->getPdo(), $secondary->getPdo());

            // A primeira operação gasta 7 EUR e mantém o lock até ao commit externo.
            $primary->beginTransaction();
            app(FinancialMovementService::class)->create($user, [
                'type' => 'expense',
                'description' => 'Primeiro débito concorrente',
                'amount' => '7.00',
                'movement_date' => '2026-11-18',
                'source_account_id' => $account->id,
            ]);

            $secondary->beginTransaction();
            $lockFailure = null;
            try {
                $secondary->select(
                    'SELECT id FROM contas_financeiras WHERE user_id = ? AND id = ? FOR UPDATE NOWAIT',
                    [$user->id, $account->id]
                );
            } catch (QueryException $exception) {
                $lockFailure = $exception;
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $this->assertNotNull($lockFailure, 'A segunda sessão não deve adquirir a conta enquanto o primeiro débito está por confirmar.');
            $this->assertStringContainsString('3572', $lockFailure->getMessage());
            $primary->commit();

            // Depois do primeiro commit, a segunda sessão lê saldo 3 EUR e rejeita
            // o segundo débito de 7 EUR, em vez de gastar o mesmo saldo novamente.
            config(['database.default' => 'concurrent']);
            try {
                app(FinancialMovementService::class)->create($user, [
                    'type' => 'expense',
                    'description' => 'Segundo débito concorrente',
                    'amount' => '7.00',
                    'movement_date' => '2026-11-18',
                    'source_account_id' => $account->id,
                ]);
                $this->fail('O segundo débito devia ser rejeitado depois de o saldo cair para 3 EUR.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
            config(['database.default' => $primaryName]);

            $this->assertSame('3.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($account->fresh()));
            $this->assertDatabaseCount('historico_movimentos', 1);
        } finally {
            config(['database.default' => $primaryName]);

            if ($secondary !== null && $secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            if ($fixturesCommitted) {
                DB::table('historico_movimentos')->where('user_id', $user->id)->delete();
                DB::table('movimentos')->where('user_id', $user->id)->delete();
                DB::table('contas_financeiras')->where('user_id', $user->id)->delete();
                DB::table('users')->where('id', $user->id)->delete();
            }
            if ($secondary !== null) {
                DB::disconnect('concurrent');
                DB::purge('concurrent');
            }
            config(['database.connections.concurrent' => null]);
        }
    }

    public function test_two_mysql_sessions_serialize_the_same_movement_deletion(): void
    {
        $primaryName = config('database.default');
        $primary = DB::connection($primaryName);

        if ($primary->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Este teste exige MySQL/InnoDB e duas ligações independentes.');
        }

        $user = User::factory()->create();
        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Movimento para teste de concorrência',
            'amount' => '25.00',
            'movement_date' => '2026-11-18',
        ]);

        $fixturesCommitted = false;
        $secondary = null;

        try {
            // RefreshDatabase abre uma transação por teste. O commit explícito
            // torna os fixtures visíveis à segunda sessão MySQL.
            $primary->commit();
            $fixturesCommitted = true;

            config(['database.connections.concurrent' => config('database.connections.'.$primaryName)]);
            DB::purge('concurrent');
            $secondary = DB::connection('concurrent');

            $this->assertNotSame($primary->getPdo(), $secondary->getPdo(), 'As operações devem usar sessões PDO independentes.');

            // A transação vencedora retém o lock do movimento.
            $primary->beginTransaction();

            try {
                $lockedMovement = $primary->table('movimentos')
                    ->where('user_id', $user->id)
                    ->where('id', $movement->id)
                    ->lockForUpdate()
                    ->first();

                $this->assertNotNull($lockedMovement, 'A primeira transação deve adquirir o lock do movimento.');

                // A segunda sessão tenta bloquear a mesma linha com NOWAIT.
                // O erro imediato confirma a contenção do lock InnoDB.
                $secondary->beginTransaction();
                $lockFailure = null;

                try {
                    $secondary->select(
                        'SELECT id FROM movimentos WHERE user_id = ? AND id = ? FOR UPDATE NOWAIT',
                        [$user->id, $movement->id]
                    );
                } catch (QueryException $exception) {
                    $lockFailure = $exception;
                } finally {
                    if ($secondary->transactionLevel() > 0) {
                        $secondary->rollBack();
                    }
                }

                $this->assertNotNull($lockFailure, 'A segunda transação não deve adquirir um lock já mantido pela primeira.');
                $this->assertStringContainsString('3572', $lockFailure->getMessage());

                // A exclusão real é concluída pela transação vencedora.
                app(FinancialMovementService::class)->deleteAsCorrection(
                    $user,
                    $movement,
                    'Concorrência — transação vencedora'
                );
                $primary->commit();
            } catch (\Throwable $exception) {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
                if ($primary->transactionLevel() > 0) {
                    $primary->rollBack();
                }
                throw $exception;
            }

            // A segunda sessão repete a exclusão após o commit: deve ser
            // idempotente e não criar uma segunda operação nem itens de auditoria.
            config(['database.default' => 'concurrent']);
            $this->assertSame($secondary->getPdo(), DB::connection()->getPdo(), 'A repetição deve executar na segunda sessão MySQL.');

            $result = app(FinancialMovementService::class)->deleteAsCorrection(
                $user,
                $movement->fresh(),
                'Concorrência — repetição após commit'
            );
            config(['database.default' => $primaryName]);

            $this->assertSame('void', $result->fresh()->status);
            $this->assertDatabaseCount('deletion_operations', 1);
            $this->assertDatabaseCount('deletion_operation_items', 1);
            $this->assertDatabaseHas('deletion_operations', [
                'root_entity_type' => 'movement',
                'root_entity_id' => $movement->id,
                'status' => 'completed',
            ]);
        } finally {
            config(['database.default' => $primaryName]);

            if ($secondary !== null && $secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }

            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }

            // Como o fixture foi commitado para ser partilhado entre sessões,
            // remove o utilizador e os seus registos dependentes explicitamente.
            if ($fixturesCommitted) {
                DB::table('users')->where('id', $user->id)->delete();
            }

            if ($secondary !== null) {
                DB::disconnect('concurrent');
                DB::purge('concurrent');
            }

            config(['database.connections.concurrent' => null]);
        }
    }
}
