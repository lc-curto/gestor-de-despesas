<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialDeletionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_mysql_processes_serialize_the_same_movement_deletion(): void
    {
        $primaryName = config('database.default');
        $primary = DB::connection($primaryName);

        if ($primary->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Este teste exige MySQL/InnoDB.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Este teste exige suporte a processos PHP (pcntl).');
        }

        $user = User::factory()->create();
        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Movimento para teste de concorrência',
            'amount' => '25.00',
            'movement_date' => '2026-11-18',
        ]);

        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsResource($parentSocket);
        $this->assertIsResource($childSocket);

        // A transação principal retém o lock. O processo filho executa a mesma
        // operação de domínio por outra ligação PDO e fica bloqueado no InnoDB.
        $primary->beginTransaction();
        $lockedMovement = $primary->table('movimentos')
            ->where('user_id', $user->id)
            ->where('id', $movement->id)
            ->lockForUpdate()
            ->first();
        $this->assertNotNull($lockedMovement, 'A transação principal deve adquirir o lock do movimento.');

        $pid = pcntl_fork();
        if ($pid === -1) {
            $primary->rollBack();
            fclose($parentSocket);
            fclose($childSocket);
            $this->fail('Não foi possível iniciar o processo concorrente.');
        }

        if ($pid === 0) {
            fclose($parentSocket);

            // O filho usa uma segunda ligação; não fechar a ligação herdada,
            // pois ela pertence à transação que o processo pai ainda vai concluir.
            config(['database.connections.concurrent' => config('database.connections.'.$primaryName)]);
            config(['database.default' => 'concurrent']);

            try {
                $threadId = DB::connection('concurrent')->selectOne('SELECT CONNECTION_ID() AS id')->id;
                fwrite($childSocket, 'started:'.$threadId."\n");
                app(FinancialMovementService::class)->deleteAsCorrection(
                    $user,
                    $movement,
                    'Concorrência — processo concorrente'
                );
                fwrite($childSocket, "ok\n");
                fclose($childSocket);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($childSocket, 'error:'.$exception::class.': '.$exception->getMessage()."\n");
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);

        try {
            $started = fgets($parentSocket);
            $this->assertIsString($started);
            $this->assertStringStartsWith('started:', $started);
            $childThreadId = (int) trim(substr($started, strlen('started:')));

            // Aguarda a confirmação do InnoDB de que este filho está realmente
            // em LOCK WAIT; isto evita depender de sleeps ou de timing arbitrário.
            $deadline = microtime(true) + 5;
            $waitingTransactions = 0;

            do {
                $row = $primary->selectOne(
                    "SELECT COUNT(*) AS waiting FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'",
                    [$childThreadId]
                );
                $waitingTransactions = (int) ($row->waiting ?? 0);

                if ($waitingTransactions > 0) {
                    break;
                }

                $childStatus = 0;
                if (pcntl_waitpid($pid, $childStatus, WNOHANG) > 0) {
                    throw new \RuntimeException('O processo concorrente terminou antes de aguardar o lock.');
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            $this->assertSame(1, $waitingTransactions, 'O segundo processo deve estar bloqueado pelo lock InnoDB da primeira transação.');

            // A operação vencedora conclui e liberta o lock. O processo filho
            // acorda, relê o estado atual (void) e termina sem duplicar auditoria.
            app(FinancialMovementService::class)->deleteAsCorrection(
                $user,
                $movement,
                'Concorrência — processo principal'
            );
            $primary->commit();
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            // O rollback liberta o lock e permite que o processo filho termine.
            pcntl_waitpid($pid, $childStatus);
            DB::purge($primaryName);
            DB::connection($primaryName);
            fclose($parentSocket);
            throw $exception;
        }

        pcntl_waitpid($pid, $childStatus);
        // O processo filho pode fechar o socket PDO herdado ao terminar.
        // Reabre a ligação principal depois de ambos os processos concluírem.
        DB::purge($primaryName);
        $primary = DB::connection($primaryName);
        $childOutput = fgets($parentSocket);
        fclose($parentSocket);

        $this->assertTrue(pcntl_wifexited($childStatus), 'O processo concorrente deve terminar normalmente.');
        $this->assertSame(0, pcntl_wexitstatus($childStatus), $childOutput);
        $this->assertSame("ok\n", $childOutput);
        $this->assertSame('void', $movement->fresh()->status);
        $this->assertDatabaseCount('deletion_operations', 1);
        $this->assertDatabaseCount('deletion_operation_items', 1);
        $this->assertDatabaseHas('deletion_operations', [
            'root_entity_type' => 'movement',
            'root_entity_id' => $movement->id,
            'status' => 'completed',
        ]);
    }
}
