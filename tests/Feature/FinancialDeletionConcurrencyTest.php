<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialDeletionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

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
