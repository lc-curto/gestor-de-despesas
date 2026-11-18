<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\Divida;
use App\Models\ParcelaDivida;
use App\Models\User;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialArchivedCommitmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_command_reports_movements_still_linked_to_archived_commitments(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, now());
        $installment = $debt->parcelas()->firstOrFail();

        app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Previsão ligada a compra arquivada',
            'amount' => '30.00',
            'movement_date' => today()->toDateString(),
            'debt_id' => $debt->id,
            'installment_id' => $installment->id,
        ]);

        $this->artisan('financial:check-commitments', ['--user' => $user->id])
            ->expectsOutputToContain('Movimentos ligados a parcelamentos arquivados/eliminados: 1')
            ->expectsOutputToContain('Modo de leitura: nenhum dado foi alterado.')
            ->assertExitCode(0);
    }

    public function test_safe_integrity_repair_restores_only_the_debt_link_confirmed_by_installment(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, null);
        $installment = $debt->parcelas()->firstOrFail();

        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Previsão com relação incompleta',
            'amount' => '30.00',
            'movement_date' => today()->toDateString(),
            'installment_id' => $installment->id,
        ]);

        $this->assertNull(DB::table('movimentos')->where('id', $movement->id)->value('debt_id'));

        $this->artisan('financial:check-commitments', [
            '--user' => $user->id,
            '--repair-safe-links' => true,
        ])
            ->expectsOutputToContain('1 ligações inequívocas corrigidas')
            ->assertExitCode(0);

        $this->assertDatabaseHas('movimentos', [
            'id' => $movement->id,
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'installment_id' => $installment->id,
            'status' => 'pending',
            'amount' => '30.00',
        ]);

        $this->assertDatabaseHas('financial_integrity_corrections', [
            'user_id' => $user->id,
            'entity_type' => 'Movimento',
            'entity_id' => $movement->id,
            'correction_type' => 'installment_parent_alignment',
        ]);
    }

    public function test_archived_commitments_are_recoverable_from_archived_and_all_tabs(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, now());

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class)->actingAs($user);

        $this->get(route('financial.commitments.page', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Compra QA arquivada')
            ->assertSee('Arquivados (1)');

        $this->get(route('financial.commitments.page', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Compra QA arquivada');

        $this->get(route('financial.commitments.page', ['status' => 'active']))
            ->assertOk()
            ->assertDontSee('Compra QA arquivada');
    }

    public function test_cancelled_commitments_remain_accessible_in_archived_tab(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, null);
        $debt->update(['status' => 'cancelled']);

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class)->actingAs($user);

        $this->get(route('financial.commitments.page', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Compra QA arquivada')
            ->assertSee('Cancelado')
            ->assertSee('Arquivados (1)');
    }

    public function test_archived_commitment_can_be_restored_without_changing_installments_or_payments(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, now());

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class)->actingAs($user);

        $this->postJson(route('financial.commitments.restore', $debt->id))
            ->assertOk()
            ->assertJsonPath('message', 'Compra restaurada.');

        $this->assertDatabaseHas('dividas', [
            'id' => $debt->id,
            'user_id' => $user->id,
            'archived_at' => null,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('parcelas_divida', [
            'debt_id' => $debt->id,
            'installment_number' => 1,
            'amount' => '30.00',
            'paid_amount' => '0.00',
            'status' => 'pending',
        ]);
    }

    public function test_soft_deleted_commitment_with_legacy_links_can_be_recovered_into_archived_review(): void
    {
        $user = User::factory()->create();
        $debt = $this->createCommitment($user, null);
        $debt->delete();

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class)->actingAs($user);

        $this->get(route('financial.commitments.page', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Compra QA arquivada')
            ->assertSee('Arquivados (1)');

        $this->postJson(route('financial.commitments.restore', $debt->id))
            ->assertOk()
            ->assertJsonPath('message', 'Compra restaurada.');

        $this->assertDatabaseHas('dividas', [
            'id' => $debt->id,
            'deleted_at' => null,
            'status' => 'open',
        ]);
        $this->assertNotNull(DB::table('dividas')->where('id', $debt->id)->value('archived_at'));
    }

    private function createCommitment(User $user, $archivedAt): Divida
    {
        $debt = Divida::query()->create([
            'user_id' => $user->id,
            'name' => 'Compra QA arquivada',
            'type' => 'purchase',
            'payment_condition' => 'installments',
            'payment_method' => 'debit',
            'installments_count' => 2,
            'installment_frequency' => 'monthly',
            'total_amount' => '60.00',
            'start_date' => today()->toDateString(),
            'due_date' => today()->addMonth()->toDateString(),
            'status' => 'open',
            'archived_at' => $archivedAt,
        ]);

        ParcelaDivida::query()->create([
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'installment_number' => 1,
            'due_date' => today()->addMonth()->toDateString(),
            'amount' => '30.00',
            'principal_amount' => '30.00',
            'interest_amount' => '0.00',
            'paid_amount' => '0.00',
            'status' => 'pending',
        ]);

        ParcelaDivida::query()->create([
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'installment_number' => 2,
            'due_date' => today()->addMonths(2)->toDateString(),
            'amount' => '30.00',
            'principal_amount' => '30.00',
            'interest_amount' => '0.00',
            'paid_amount' => '0.00',
            'status' => 'pending',
        ]);

        return $debt;
    }
}
