<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialDeletionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

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

        config(['database.connections.concurrent' => config('database.connections.'.$primaryName)]);
        DB::purge('concurrent');
        $secondary = DB::connection('concurrent');

        $this->assertNotSame($primary->getPdo(), $secondary->getPdo(), 'As operações devem usar sessões PDO independentes.');

        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $secondary->statement('SET SESSION lock_wait_timeout = 1');

        // A transação vencedora retém o lock do movimento.
        $primary->beginTransaction();

        try {
            $lockedMovement = $primary->table('movimentos')
                ->where('user_id', $user->id)
                ->where('id', $movement->id)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedMovement, 'A primeira transação deve adquirir o lock do movimento.');

            // A segunda sessão inicia outra transação e tenta bloquear a mesma
            // linha. O timeout confirma que o InnoDB está a serializar as operações.
            $secondary->beginTransaction();
            $lockFailure = null;

            try {
                $secondary->table('movimentos')
                    ->where('user_id', $user->id)
                    ->where('id', $movement->id)
                    ->lockForUpdate()
                    ->first();
            } catch (QueryException $exception) {
                $lockFailure = $exception;
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $this->assertNotNull($lockFailure, 'A segunda transação deve aguardar o lock mantido pela primeira.');
            $this->assertStringContainsString('1205', $lockFailure->getMessage());

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
        } finally {
            config(['database.default' => $primaryName]);
        }

        // A segunda sessão repete a exclusão após o commit: deve ser idempotente
        // e não criar uma segunda operação nem itens de auditoria.
        config(['database.default' => 'concurrent']);

        try {
            $result = app(FinancialMovementService::class)->deleteAsCorrection(
                $user,
                $movement->fresh(),
                'Concorrência — repetição após commit'
            );
        } finally {
            config(['database.default' => $primaryName]);
        }

        $this->assertSame('void', $result->fresh()->status);
        $this->assertDatabaseCount('deletion_operations', 1);
        $this->assertDatabaseCount('deletion_operation_items', 1);
        $this->assertDatabaseHas('deletion_operations', [
            'root_entity_type' => 'movement',
            'root_entity_id' => $movement->id,
            'status' => 'completed',
        ]);

        DB::disconnect('concurrent');
        DB::purge('concurrent');
        config(['database.connections.concurrent' => null]);
    }
}
