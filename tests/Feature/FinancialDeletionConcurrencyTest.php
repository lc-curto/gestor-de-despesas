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

        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
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

            // Nunca reutilizar no filho o socket PDO herdado do processo pai.
            DB::purge($primaryName);

            try {
                app(FinancialMovementService::class)->deleteAsCorrection(
                    $user,
                    $movement,
                    'Concorrência — processo concorrente'
                );
                fwrite($childSocket, 'ok');
                fclose($childSocket);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($childSocket, $exception::class.': '.$exception->getMessage());
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);

        try {
            // Aguarda a confirmação do InnoDB de que o filho está realmente
            // em LOCK WAIT; isto evita depender de sleeps ou de timing arbitrário.
            $deadline = microtime(true) + 5;
            $waitingTransactions = 0;

            do {
                $row = $primary->selectOne(
                    "SELECT COUNT(*) AS waiting FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'"
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
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $childStatus);
            fclose($parentSocket);
            throw $exception;
        }

        pcntl_waitpid($pid, $childStatus);
        $childOutput = stream_get_contents($parentSocket);
        fclose($parentSocket);

        $this->assertTrue(pcntl_wifexited($childStatus), 'O processo concorrente deve terminar normalmente.');
        $this->assertSame(0, pcntl_wexitstatus($childStatus), $childOutput);
        $this->assertSame('ok', $childOutput);
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
