<?php

namespace App\Services\Financial;

use App\Models\Categoria;
use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\Divida;
use App\Models\Fatura;
use App\Models\HistoricoMovimento;
use App\Models\Movimento;
use App\Models\ParcelaDivida;
use App\Models\Receita;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orquestra exclusões financeiras sobre o grafo de dependências.
 *
 * Nenhum movimento financeiro é removido fisicamente: o estado void retira-o
 * dos cálculos operacionais e o histórico conserva a evidência da operação.
 */
class FinancialDeletionService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly AccountBalanceService $balances,
    ) {}

    public function deleteUnusedCard(User $user, ContaFinanceira $account): void
    {
        DB::transaction(function () use ($user, $account): void {
            $locked = ContaFinanceira::query()
                ->where('user_id', $user->id)
                ->whereKey($account->id)
                ->where('type', 'credit_card')
                ->lockForUpdate()
                ->firstOrFail();

            $hasMovements = $user->movimentos()->where(function ($query) use ($locked): void {
                $query->where('source_account_id', $locked->id)
                    ->orWhere('destination_account_id', $locked->id);
            })->exists();
            $hasDebt = Divida::withTrashed()->where('user_id', $user->id)->where('account_id', $locked->id)->exists();
            $hasRecurrence = $user->recorrencias()->where('account_id', $locked->id)->exists();
            $invoices = Fatura::query()->where('user_id', $user->id)->where('account_id', $locked->id)
                ->orderBy('id')->lockForUpdate()->get();
            $invoiceIds = $invoices->pluck('id');
            $hasInvoiceMovements = $invoiceIds->isNotEmpty()
                && Movimento::query()->where('user_id', $user->id)->whereIn('invoice_id', $invoiceIds)->exists();
            $hasCarry = $invoiceIds->isNotEmpty() && DB::table('fatura_saldos_transportados')
                ->where('user_id', $user->id)
                ->where(function ($query) use ($invoiceIds): void {
                    $query->whereIn('source_invoice_id', $invoiceIds)
                        ->orWhereIn('target_invoice_id', $invoiceIds);
                })->exists();

            $balanceCents = 0;
            if ($locked->is_operational && $locked->opening_balance !== null && $locked->opening_balance_date) {
                $balanceCents = $this->balances->toCents($this->balances->balance($locked));
            } elseif ($locked->opening_balance !== null) {
                $balanceCents = $this->balances->toCents((string) $locked->opening_balance);
            }

            if ($hasMovements || $hasDebt || $hasRecurrence || $hasInvoiceMovements || $hasCarry
                || (float) $locked->opening_debt_amount > 0
                || (float) $locked->opening_debt_paid > 0
                || $balanceCents !== 0) {
                throw ValidationException::withMessages([
                    'card' => 'Este cartão tem movimentos, dívida ou registos associados. Encerra-o para preservar o histórico.',
                ]);
            }

            $operationId = $this->startOperation($user, 'card', $locked->id, 'Cartão sem utilização eliminado.');
            foreach ($invoices as $invoice) {
                $before = $invoice->getAttributes();
                $this->recordItem($user, $operationId, 'Fatura', $invoice->id, 'hard_deleted', $before, null);
            }
            DB::table('faturas')->where('user_id', $user->id)->where('account_id', $locked->id)->delete();

            $beforeCard = $locked->getAttributes();
            $locked->delete();
            $this->recordItem($user, $operationId, 'ContaFinanceira', $locked->id, 'hard_deleted', $beforeCard, null);
            DB::table('deletion_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);
        });
    }

    public function deleteCategory(User $user, Categoria $category): void
    {
        DB::transaction(function () use ($user, $category): void {
            $locked = Categoria::query()->where('user_id', $user->id)->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $legacyCount = $locked->type === 'despesa'
                ? $locked->despesas()->count()
                : $locked->receitas()->count();
            $canonicalCount = Movimento::query()
                ->where('user_id', $user->id)
                ->where('category_id', $locked->id)
                ->exists();

            if ($legacyCount > 0 || $canonicalCount) {
                throw ValidationException::withMessages([
                    'categoria' => 'Esta categoria tem movimentos associados, incluindo histórico. Desativa-a para preservar as classificações existentes.',
                ]);
            }

            $operationId = $this->startOperation($user, 'category', $locked->id, 'Categoria sem movimentos eliminada.');
            $before = $locked->getAttributes();
            $locked->delete();
            $this->recordItem($user, $operationId, 'Categoria', $locked->id, 'hard_deleted', $before, null);
            DB::table('deletion_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);
        });
    }

    public function deleteLegacyRecord(User $user, string $type, string $id): void
    {
        DB::transaction(function () use ($user, $type, $id): void {
            if (! in_array($type, ['despesa', 'receita'], true)) {
                throw ValidationException::withMessages(['movement' => 'Tipo de registo legacy inválido.']);
            }

            $query = $type === 'despesa' ? Despesa::query() : Receita::query();
            $record = $query->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
            if (! $record) {
                return;
            }

            $operationId = $this->startOperation($user, 'legacy_'.$type, $id, 'Registo legacy eliminado pelo utilizador.');
            $before = $record->getAttributes();
            $record->delete();
            $this->recordItem($user, $operationId, ucfirst($type), $id, 'hard_deleted', $before, null);
            DB::table('deletion_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);
        });
    }

    public function deleteMovement(
        User $user,
        Movimento $movement,
        ?string $reason = null,
        bool $deleteLegacy = true,
        bool $detachRecurrence = false,
    ): Movimento {
        return DB::transaction(function () use ($user, $movement, $reason, $deleteLegacy, $detachRecurrence): Movimento {
            $snapshot = Movimento::query()
                ->where('user_id', $user->id)
                ->whereKey($movement->id)
                ->firstOrFail();

            // O agregado é sempre bloqueado antes do evento, na mesma ordem usada
            // pela liquidação. Isto serializa exclusões concorrentes de pagamentos.
            $parent = $snapshot->settles_movement_id
                ? Movimento::query()->where('user_id', $user->id)->whereKey($snapshot->settles_movement_id)->lockForUpdate()->first()
                : null;

            $legacyPairIds = $snapshot->reversal_of_id
                ? collect([$snapshot->reversal_of_id])
                : Movimento::query()->where('user_id', $user->id)->where('reversal_of_id', $snapshot->id)->pluck('id');
            $lockIds = collect([$snapshot->id])->merge($legacyPairIds)->filter()->unique()->sort()->values();
            $lockedRows = Movimento::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $lockIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $locked = $lockedRows->get($snapshot->id);
            if (! $locked) {
                throw ValidationException::withMessages(['movement' => 'O movimento já não está disponível.']);
            }
            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->debt_id || $locked->installment_id) {
                throw ValidationException::withMessages([
                    'movement' => 'Este movimento pertence a um parcelamento. Elimina ou gere o parcelamento na respetiva área para resolver todas as parcelas e pagamentos.',
                ]);
            }
            if ($locked->status === 'partial' && ! $locked->is_settlement_parent) {
                throw ValidationException::withMessages([
                    'movement' => 'Este movimento tem liquidação parcial sem uma relação de origem identificável. É necessária reconciliação antes de o eliminar.',
                ]);
            }
            if ($locked->is_settlement_parent && $locked->invoice_id) {
                throw ValidationException::withMessages([
                    'movement' => 'Esta é a obrigação calculada de uma fatura. Elimina ou corrige os movimentos de origem; o sistema recalcula a fatura automaticamente.',
                ]);
            }

            if ($locked->settles_movement_id && (! $parent || ! $parent->is_settlement_parent)) {
                throw ValidationException::withMessages([
                    'movement' => 'O pagamento está associado a uma obrigação inexistente ou inconsistente. A exclusão foi bloqueada para evitar uma relação órfã.',
                ]);
            }

            $invoice = null;
            $isInvoicePayment = $locked->type === 'transfer' && $locked->invoice_id;
            if ($locked->invoice_id) {
                $invoice = Fatura::query()
                    ->where('user_id', $user->id)
                    ->whereKey($locked->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $isInvoicePayment) {
                    $this->movements->assertInvoiceReconciled($user, $invoice);
                }
                $hasPayments = $invoice->movimentos()
                    ->where('type', 'transfer')
                    ->where('status', 'settled')
                    ->exists();
                $hasCarry = DB::table('fatura_saldos_transportados')
                    ->where('user_id', $user->id)
                    ->where(function ($query) use ($invoice): void {
                        $query->where('source_invoice_id', $invoice->id)
                            ->orWhere('target_invoice_id', $invoice->id);
                    })->exists();

                if (! $isInvoicePayment && (in_array($invoice->status, ['closed', 'paid', 'carried'], true) || $hasPayments || $hasCarry)) {
                    throw ValidationException::withMessages([
                        'movement' => 'Este movimento pertence a uma fatura fechada, paga ou com pagamentos/saldos associados. A exclusão isolada foi bloqueada para preservar as alocações e os ciclos seguintes.',
                    ]);
                }
            }

            $openingDebtCard = null;
            if ($locked->opening_debt_card_id) {
                $openingDebtCard = ContaFinanceira::query()
                    ->where('user_id', $user->id)
                    ->whereKey($locked->opening_debt_card_id)
                    ->where('type', 'credit_card')
                    ->lockForUpdate()
                    ->firstOrFail();

                $paidCents = $this->balances->toCents((string) ($openingDebtCard->opening_debt_paid ?? '0.00'));
                $movementCents = $this->balances->toCents((string) $locked->amount);
                if ($movementCents > $paidCents) {
                    throw ValidationException::withMessages([
                        'movement' => 'O contador da dívida inicial do cartão não permite remover este pagamento com segurança. Reconcilia a dívida antes de continuar.',
                    ]);
                }
            } elseif (
                $locked->type === 'transfer'
                && ! $locked->invoice_id
                && $locked->destination_account_id
                && str_starts_with((string) $locked->description, 'Pagamento de dívida inicial · ')
                && $user->contasFinanceiras()->whereKey($locked->destination_account_id)->where('type', 'credit_card')->exists()
            ) {
                throw ValidationException::withMessages([
                    'movement' => 'Este pagamento antigo de dívida inicial não tem identificação suficiente. Reconcilia o cartão antes de o eliminar.',
                ]);
            }

            $children = collect();
            if ($locked->is_settlement_parent) {
                $children = Movimento::query()
                    ->where('user_id', $user->id)
                    ->where('settles_movement_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $legacyPair = $lockedRows->except($locked->id)->values();

            $operationId = $this->startOperation(
                $user,
                'movement',
                $locked->id,
                $reason ?? 'Movimento eliminado pelo utilizador.',
                ['children_count' => $children->count(), 'legacy_pair_count' => $legacyPair->count()]
            );

            if ($openingDebtCard) {
                $paidCents = $this->balances->toCents((string) $openingDebtCard->opening_debt_paid)
                    - $this->balances->toCents((string) $locked->amount);
                $beforeCard = $openingDebtCard->getAttributes();
                $openingDebtCard->update(['opening_debt_paid' => number_format($paidCents / 100, 2, '.', '')]);
                $this->recordItem($user, $operationId, 'ContaFinanceira', $openingDebtCard->id, 'recalculated', $beforeCard, $openingDebtCard->fresh()->getAttributes());
            }

            foreach ($children as $child) {
                if ($child->status === 'void') {
                    continue;
                }
                $this->voidMovement($user, $operationId, $child, $reason ?? 'Pagamento propagado pela exclusão do movimento de origem.');
            }

            foreach ($legacyPair as $paired) {
                if ($paired->status !== 'void') {
                    $this->voidMovement($user, $operationId, $paired, $reason ?? 'Registo legado relacionado removido na mesma operação.');
                }
            }

            $this->voidMovement(
                $user,
                $operationId,
                $locked,
                $reason ?? 'Movimento eliminado pelo utilizador.',
                $detachRecurrence
            );

            if ($deleteLegacy && $locked->legacy_type && $locked->legacy_id) {
                if ($locked->legacy_type === 'despesa') {
                    $legacy = Despesa::query()->where('user_id', $user->id)->whereKey($locked->legacy_id)->first();
                    if ($legacy) {
                        $beforeLegacy = $legacy->getAttributes();
                        $legacy->delete();
                        $this->recordItem($user, $operationId, 'Despesa', $legacy->id, 'hard_deleted', $beforeLegacy, null);
                    }
                } elseif ($locked->legacy_type === 'receita') {
                    $legacy = Receita::query()->where('user_id', $user->id)->whereKey($locked->legacy_id)->first();
                    if ($legacy) {
                        $beforeLegacy = $legacy->getAttributes();
                        $legacy->delete();
                        $this->recordItem($user, $operationId, 'Receita', $legacy->id, 'hard_deleted', $beforeLegacy, null);
                    }
                }
            }

            if ($parent) {
                $this->movements->refreshSettlementParentAfterDeletion($user, $parent->id);
            }
            if ($invoice) {
                $this->movements->syncInvoiceSettlementParent($user, $invoice);
            }

            DB::table('deletion_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Elimina a obrigação, mas preserva pagamentos reais como movimentos autónomos.
     * As previsões e associações exclusivas são anuladas na mesma transação.
     */
    public function deleteCommitment(User $user, Divida $debt, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $debt, $reason): void {
            $lockedDebt = Divida::query()
                ->where('user_id', $user->id)
                ->whereKey($debt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $installments = ParcelaDivida::query()
                ->where('user_id', $user->id)
                ->where('debt_id', $lockedDebt->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $installmentIds = $installments->pluck('id');

            $related = Movimento::query()
                ->where('user_id', $user->id)
                ->where(function ($query) use ($lockedDebt, $installmentIds): void {
                    $query->where('debt_id', $lockedDebt->id);
                    if ($installmentIds->isNotEmpty()) {
                        $query->orWhereIn('installment_id', $installmentIds);
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $parentIds = $related->where('is_settlement_parent', true)->pluck('id');
            $paymentEvents = $parentIds->isNotEmpty()
                ? Movimento::query()->where('user_id', $user->id)->whereIn('settles_movement_id', $parentIds)->orderBy('id')->lockForUpdate()->get()
                : collect();

            $accountIds = $related->flatMap(fn (Movimento $item) => [$item->source_account_id, $item->destination_account_id])
                ->merge($paymentEvents->flatMap(fn (Movimento $item) => [$item->source_account_id, $item->destination_account_id]))
                ->filter()->unique()->sort()->values();
            if ($accountIds->isNotEmpty()) {
                ContaFinanceira::query()->where('user_id', $user->id)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
            }

            $operationId = $this->startOperation(
                $user,
                'commitment',
                $lockedDebt->id,
                $reason ?? 'Parcelamento eliminado pelo utilizador.',
                [
                    'installments_count' => $installments->count(),
                    'payment_events_count' => $paymentEvents->where('status', 'settled')->count(),
                ]
            );

            $beforeDebt = $lockedDebt->getAttributes();
            $lockedDebt->update(['status' => 'cancelled']);
            $lockedDebt->delete();
            $this->recordItem($user, $operationId, 'Divida', $lockedDebt->id, 'soft_deleted', $beforeDebt, $lockedDebt->getAttributes());

            foreach ($installments as $installment) {
                $before = $installment->getAttributes();
                $installment->update(['status' => 'cancelled']);
                $this->recordItem($user, $operationId, 'ParcelaDivida', $installment->id, 'cancelled', $before, $installment->fresh()->getAttributes());
            }

            foreach ($related as $movementItem) {
                $before = $movementItem->getAttributes();
                $isForecastOrParent = $movementItem->is_settlement_parent
                    || in_array($movementItem->status, ['pending', 'partial'], true);
                if ($isForecastOrParent) {
                    $movementItem->update([
                        'status' => 'void',
                        'debt_id' => null,
                        'installment_id' => null,
                        'is_settlement_parent' => false,
                        'settled_at' => null,
                    ]);
                    $this->recordMovementHistory($user, $movementItem, 'deleted', $before, $movementItem->fresh()->getAttributes(), 'Previsão eliminada com o parcelamento.');
                    $this->recordItem($user, $operationId, 'Movimento', $movementItem->id, 'voided', $before, $movementItem->fresh()->getAttributes());
                } else {
                    // Movimento pago diretamente à parcela: preserva o efeito na conta,
                    // mas deixa de depender do agregado que foi eliminado.
                    $movementItem->update(['debt_id' => null, 'installment_id' => null]);
                    $this->recordMovementHistory($user, $movementItem, 'detached', $before, $movementItem->fresh()->getAttributes(), 'Pagamento preservado como movimento independente após eliminação do parcelamento.');
                    $this->recordItem($user, $operationId, 'Movimento', $movementItem->id, 'detached', $before, $movementItem->fresh()->getAttributes());
                }
            }

            foreach ($paymentEvents as $event) {
                $before = $event->getAttributes();
                if ($event->status !== 'settled') {
                    $event->update(['status' => 'void', 'settles_movement_id' => null]);
                    $action = 'voided';
                } else {
                    $event->update(['settles_movement_id' => null]);
                    $action = 'detached';
                }
                $this->recordMovementHistory($user, $event, $action === 'voided' ? 'deleted' : 'detached', $before, $event->fresh()->getAttributes(), 'Evento de pagamento resolvido durante a eliminação do parcelamento.');
                $this->recordItem($user, $operationId, 'Movimento', $event->id, $action, $before, $event->fresh()->getAttributes());
            }

            DB::table('deletion_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);
        });
    }

    private function voidMovement(
        User $user,
        string $operationId,
        Movimento $movement,
        string $reason,
        bool $detachRecurrence = false,
    ): void {
        $before = $movement->getAttributes();
        $changes = ['status' => 'void'];
        if ($detachRecurrence) {
            $changes['recurrence_id'] = null;
            $changes['recurrence_occurrence_date'] = null;
        }
        $movement->update($changes);
        $after = $movement->fresh()->getAttributes();
        $this->recordMovementHistory($user, $movement, 'deleted', $before, $after, $reason);
        $this->recordItem($user, $operationId, 'Movimento', $movement->id, 'voided', $before, $after);
    }

    private function recordMovementHistory(
        User $user,
        Movimento $movement,
        string $action,
        array $before,
        array $after,
        ?string $reason,
    ): void {
        HistoricoMovimento::query()->create([
            'user_id' => $user->id,
            'movement_id' => $movement->id,
            'changed_by' => $user->id,
            'action' => $action,
            'before_data' => $before,
            'after_data' => $after,
            'reason' => $reason,
        ]);
    }

    private function startOperation(
        User $user,
        string $entityType,
        string $entityId,
        ?string $reason,
        array $metadata = [],
    ): string {
        $id = (string) Str::uuid();
        DB::table('deletion_operations')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'requested_by' => $user->id,
            'root_entity_type' => $entityType,
            'root_entity_id' => $entityId,
            'reason' => $reason,
            'status' => 'processing',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function recordItem(
        User $user,
        string $operationId,
        string $entityType,
        string $entityId,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        DB::table('deletion_operation_items')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'deletion_operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
