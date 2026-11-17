<?php

namespace App\Services\Financial;

use App\Models\Categoria;
use App\Models\Divida;
use App\Models\Movimento;
use App\Models\ParcelaDivida;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialCommitmentService
{
    public function __construct(private readonly FinancialMovementService $movements) {}

    public function create(User $user, array $data): Divida
    {
        return DB::transaction(function () use ($user, $data): Divida {
            $condition = $data['payment_condition'];
            $category = !empty($data['category_id'])
                ? Categoria::query()->where('user_id', $user->id)->whereKey($data['category_id'])->where('type', 'despesa')->where('is_active', true)->first()
                : null;
            if (!empty($data['category_id']) && !$category) {
                throw ValidationException::withMessages(['category_id' => 'Seleciona uma categoria de despesa ativa.']);
            }
            $expenseBehavior = $category?->expense_behavior ?? 'variable';
            if (in_array($data['payment_method'] ?? null, ['credit', 'card'], true)) {
                throw ValidationException::withMessages(['payment_method' => 'O parcelamento por cartão não está disponível neste formulário. Regista a compra em Cartões.']);
            }
            $count = (int) ($data['installments_count'] ?? 1);
            if ($condition === 'installments' && $count < 2) throw ValidationException::withMessages(['installments_count' => 'Um compromisso parcelado precisa de pelo menos 2 parcelas.']);
            if ($condition !== 'installments') $count = 1;
            $start = Carbon::parse($data['start_date']);
            $totalCents = (int) round(((float) $data['total_amount']) * 100);
            $base = intdiv($totalCents, $count);
            if ($base < 1) {
                throw ValidationException::withMessages(['total_amount' => 'O valor total deve permitir pelo menos um cêntimo por prestação.']);
            }
            $remainder = $totalCents % $count;
            $debt = Divida::query()->create([
                'user_id' => $user->id, 'name' => $data['name'], 'merchant' => $data['merchant'] ?? null, 'type' => 'purchase',
                'payment_condition' => $condition, 'payment_method' => $data['payment_method'] ?? null,
                'installments_count' => $count, 'installment_frequency' => $data['installment_frequency'] ?? 'monthly', 'account_id' => $data['account_id'] ?? null,
                'total_amount' => $data['total_amount'], 'start_date' => $start->toDateString(),
                'due_date' => $data['due_date'] ?? $start->toDateString(), 'status' => 'open',
            ]);
            for ($i = 1; $i <= $count; $i++) {
                $amountCents = $base + ($i <= $remainder ? 1 : 0);
                ParcelaDivida::query()->create([
                    'user_id' => $user->id, 'debt_id' => $debt->id, 'installment_number' => $i,
                    'due_date' => $this->installmentDate(Carbon::parse($data['due_date'] ?? $start), $i - 1, $data['installment_frequency'] ?? 'monthly')->toDateString(),
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                    'principal_amount' => number_format($amountCents / 100, 2, '.', ''),
                    'interest_amount' => '0.00', 'paid_amount' => '0.00', 'status' => 'pending',
                ]);
                if (!in_array($data['payment_method'] ?? null, ['credit', 'card'], true)) {
                    $installment = ParcelaDivida::query()->where('user_id', $user->id)->where('debt_id', $debt->id)->where('installment_number', $i)->firstOrFail();
                    $this->movements->createPending($user, [
                        'type' => 'expense',
                        'description' => $debt->name.' · Parcela '.$i.'/'.$count,
                        'amount' => number_format($amountCents / 100, 2, '.', ''),
                        'movement_date' => $installment->due_date,
                        'source_account_id' => $data['account_id'] ?? null,
                        'category_id' => $data['category_id'] ?? null,
                        'expense_behavior' => $expenseBehavior,
                        'debt_id' => $debt->id,
                        'installment_id' => $installment->id,
                    ]);
                }
            }
            return $debt->load('parcelas');
        });
    }

    private function installmentDate(Carbon $date, int $offset, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $date->addWeeks($offset),
            'biweekly' => $date->addWeeks($offset * 2),
            default => $date->addMonthsNoOverflow($offset),
        };
    }

    public function update(User $user, Divida $debt, array $data): Divida
    {
        return DB::transaction(function () use ($user, $debt, $data): Divida {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'paid'], true)) {
                throw ValidationException::withMessages(['commitment' => 'Este compromisso já está encerrado.']);
            }

            $pendingParts = $locked->parcelas()
                ->whereIn('status', ['pending', 'partial'])
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();
            $firstNumber = $pendingParts->first()?->installment_number ?? 1;
            $newDue = isset($data['due_date']) ? Carbon::parse($data['due_date']) : null;

            if (array_key_exists('total_amount', $data)) {
                $newTotalCents = (int) round(((float) $data['total_amount']) * 100);
                $paidCents = (int) round((float) $locked->parcelas()->sum('paid_amount') * 100);
                if ($newTotalCents < $paidCents) {
                    throw ValidationException::withMessages(['total_amount' => 'O valor total não pode ser inferior ao montante já pago.']);
                }
                if ($pendingParts->isEmpty() && $newTotalCents !== $paidCents) {
                    throw ValidationException::withMessages(['total_amount' => 'Não existem prestações pendentes para redistribuir o novo valor.']);
                }
                $remainingCents = $newTotalCents - $paidCents;
                if ($pendingParts->isNotEmpty() && $remainingCents < $pendingParts->count()) {
                    throw ValidationException::withMessages(['total_amount' => 'O valor restante não permite atribuir pelo menos 0,01 € a cada prestação pendente.']);
                }
                $baseCents = $pendingParts->isNotEmpty() ? intdiv($remainingCents, $pendingParts->count()) : 0;
                $remainderCents = $pendingParts->isNotEmpty() ? $remainingCents % $pendingParts->count() : 0;
                $locked->total_amount = number_format($newTotalCents / 100, 2, '.', '');
            }

            $locked->update([
                'name' => $data['name'],
                'merchant' => array_key_exists('merchant', $data) ? $data['merchant'] : $locked->merchant,
                'due_date' => $newDue?->toDateString() ?? $locked->due_date,
                'total_amount' => $locked->total_amount,
            ]);

            foreach ($pendingParts as $index => $part) {
                $offset = max(0, $part->installment_number - $firstNumber);
                $changes = [];
                if ($newDue) {
                    $changes['due_date'] = $this->installmentDate($newDue->copy(), $offset, $locked->installment_frequency ?? 'monthly')->toDateString();
                }

                if (array_key_exists('total_amount', $data)) {
                    $paidPartCents = (int) round((float) $part->paid_amount * 100);
                    $partAmountCents = $paidPartCents + $baseCents + ($index < $remainderCents ? 1 : 0);
                    $interestCents = (int) round((float) $part->interest_amount * 100);
                    if ($partAmountCents < $interestCents) {
                        throw ValidationException::withMessages(['total_amount' => 'O novo valor não cobre os juros já associados a uma prestação.']);
                    }
                    $changes['amount'] = number_format($partAmountCents / 100, 2, '.', '');
                    $changes['principal_amount'] = number_format(($partAmountCents - $interestCents) / 100, 2, '.', '');
                }

                $previousRemaining = max(0, round((float) $part->amount - (float) $part->paid_amount, 2));
                $part->update($changes);
                $forecasts = Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)
                    ->where('installment_id', $part->id)->where('status', 'pending')->get();
                foreach ($forecasts as $forecast) {
                    if ((int) round((float) $forecast->amount * 100) !== (int) round($previousRemaining * 100)) {
                        throw ValidationException::withMessages(['movement' => 'A previsão financeira não corresponde ao saldo da prestação. Revê os dados antes de editar.']);
                    }
                    $this->movements->updateCommitmentForecast($user, $locked, $part, $forecast);
                }
            }

            return $locked->refresh()->load('parcelas');
        });
    }

    /**
     * Guarda a versão anterior e substitui apenas as prestações ainda não pagas.
     * Pagamentos e prestações liquidadas/parcialmente liquidadas permanecem intactos.
     */
    public function renegotiate(User $user, Divida $debt, array $data): Divida
    {
        return DB::transaction(function () use ($user, $debt, $data): Divida {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'paid'], true)) {
                throw ValidationException::withMessages(['commitment' => 'Só é possível renegociar um parcelamento ativo.']);
            }

            $parts = $locked->parcelas()->orderBy('installment_number')->lockForUpdate()->get();
            $paidCents = (int) round((float) $parts->sum('paid_amount') * 100);
            $oldRemainingCents = (int) round($parts->whereIn('status', ['pending', 'partial'])->sum(fn ($part) => max(0, (float) $part->amount - (float) $part->paid_amount)) * 100);
            if ($oldRemainingCents <= 0) {
                throw ValidationException::withMessages(['commitment' => 'Este parcelamento já não tem saldo por pagar.']);
            }

            $historicalCount = (int) $parts->whereIn('status', ['paid', 'partial'])->count();
            $count = (int) $data['installments_count'];
            $frequency = $data['installment_frequency'];
            $remainingCents = array_key_exists('remaining_amount', $data)
                ? (int) round(((float) $data['remaining_amount']) * 100)
                : $oldRemainingCents;
            if ($remainingCents < 1 || $remainingCents < $count) {
                throw ValidationException::withMessages(['remaining_amount' => 'O saldo renegociado deve permitir pelo menos 0,01 € por prestação.']);
            }

            DB::table('dividas_renegociacoes')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'debt_id' => $locked->id,
                'previous_terms' => json_encode([
                    'total_amount' => $locked->total_amount,
                    'installments_count' => $locked->installments_count,
                    'installment_frequency' => $locked->installment_frequency,
                    'due_date' => $locked->due_date?->toDateString(),
                    'remaining_amount' => number_format($oldRemainingCents / 100, 2, '.', ''),
                ], JSON_THROW_ON_ERROR),
                'new_terms' => json_encode([
                    'remaining_amount' => number_format($remainingCents / 100, 2, '.', ''),
                    'installments_count' => $count,
                    'installment_frequency' => $frequency,
                    'due_date' => $data['due_date'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($parts->whereIn('status', ['pending', 'partial']) as $part) {
                $part->update(['status' => 'cancelled']);
                Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)
                    ->where('installment_id', $part->id)->where('status', 'pending')->get()
                    ->each(fn (Movimento $forecast) => $this->movements->voidRenegotiatedCommitmentForecast($user, $locked, $forecast));
            }

            $base = intdiv($remainingCents, $count);
            $remainder = $remainingCents % $count;
            $firstNumber = $historicalCount + 1;
            $firstDue = Carbon::parse($data['due_date']);
            $locked->update([
                'total_amount' => number_format(($paidCents + $remainingCents) / 100, 2, '.', ''),
                'installments_count' => $historicalCount + $count,
                'installment_frequency' => $frequency,
                'due_date' => $firstDue->toDateString(),
                'status' => 'open',
            ]);

            for ($i = 0; $i < $count; $i++) {
                $amountCents = $base + ($i < $remainder ? 1 : 0);
                $number = $firstNumber + $i;
                $dueDate = $this->installmentDate($firstDue->copy(), $i, $frequency);
                $part = ParcelaDivida::query()->create([
                    'user_id' => $user->id,
                    'debt_id' => $locked->id,
                    'installment_number' => $number,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                    'principal_amount' => number_format($amountCents / 100, 2, '.', ''),
                    'interest_amount' => '0.00',
                    'paid_amount' => '0.00',
                    'status' => 'pending',
                ]);
                $this->movements->createPending($user, [
                    'type' => 'expense',
                    'description' => $locked->name.' · Parcela '.$number.'/'.$locked->installments_count,
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                    'movement_date' => $dueDate->toDateString(),
                    'source_account_id' => $locked->account_id,
                    'debt_id' => $locked->id,
                    'installment_id' => $part->id,
                ]);
            }

            return $locked->refresh()->load('parcelas');
        });
    }

    public function archive(User $user, Divida $debt): Divida
    {
        return DB::transaction(function () use ($user, $debt): Divida {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['commitment' => 'Uma compra cancelada não pode ser arquivada nesta operação.']);
            }
            $locked->update(['archived_at' => now()]);
            return $locked->refresh();
        });
    }

    public function deleteUnused(User $user, Divida $debt): void
    {
        DB::transaction(function () use ($user, $debt): void {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $hasPayments = $locked->parcelas()->where('paid_amount', '>', 0)->exists()
                || Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)->where('status', 'settled')->exists();
            if ($hasPayments) {
                throw ValidationException::withMessages(['commitment' => 'Esta compra já tem pagamentos registados. Arquiva ou cancela-a; não pode ser eliminada.']);
            }
            $locked->update(['status' => 'cancelled']);
            $locked->parcelas()->whereIn('status', ['pending', 'partial'])->update(['status' => 'cancelled']);
            Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)->where('status', 'pending')->get()
                ->each(fn (Movimento $forecast) => $this->movements->voidCancelledCommitmentForecast($user, $locked, $forecast));
            // Soft delete: preserve installments and renegotiation audit history.
            $locked->delete();
        });
    }

    public function cancel(User $user, Divida $debt): Divida
    {
        return DB::transaction(function () use ($user, $debt): Divida {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'paid'], true)) throw ValidationException::withMessages(['commitment' => 'Este compromisso já está encerrado.']);
            $locked->parcelas()->whereIn('status', ['pending', 'partial'])->update(['status' => 'cancelled']);
            $locked->update(['status' => 'cancelled']);
            Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)->where('status', 'pending')->get()
                ->each(fn (Movimento $movement) => $this->movements->voidCancelledCommitmentForecast($user, $locked, $movement));
            return $locked->refresh();
        });
    }

    public function payInstallment(User $user, Divida $debt, ParcelaDivida $installment, array $data): ParcelaDivida
    {
        return $this->payInstallmentWithMovement($user, $debt, $installment, $data)['installment'];
    }

    /** @return array{installment: ParcelaDivida, movement: Movimento} */
    public function payInstallmentWithMovement(User $user, Divida $debt, ParcelaDivida $installment, array $data, ?string $expectedForecastId = null): array
    {
        return DB::transaction(function () use ($user, $debt, $installment, $data, $expectedForecastId): array {
            $lockedDebt = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedDebt->status, ['cancelled', 'paid'], true)) {
                throw ValidationException::withMessages(['commitment' => 'Não é possível pagar uma obrigação que já foi encerrada.']);
            }
            $locked = ParcelaDivida::query()->where('user_id', $user->id)->where('debt_id', $lockedDebt->id)->whereKey($installment->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'paid'], true)) {
                throw ValidationException::withMessages(['installment' => 'Não é possível pagar uma prestação cancelada ou já liquidada.']);
            }
            $paymentAccount = $user->contasFinanceiras()->whereKey($data['account_id'])->firstOrFail();
            if (!in_array($paymentAccount->type, ['bank', 'cash'], true) || !$paymentAccount->is_operational || $paymentAccount->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Uma parcela deve ser liquidada a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            $remaining = round((float) $locked->amount - (float) $locked->paid_amount, 2);
            $amount = round((float) ($data['amount'] ?? $remaining), 2);
            if ($remaining <= 0 || $amount <= 0 || $amount > $remaining) throw ValidationException::withMessages(['amount' => 'O valor do pagamento excede o saldo da parcela.']);
            $forecast = Movimento::query()->where('user_id', $user->id)->where('debt_id', $lockedDebt->id)
                ->where('installment_id', $locked->id)->where('status', 'pending')
                ->when($expectedForecastId, fn ($query) => $query->whereKey($expectedForecastId))->lockForUpdate()->first();
            if ($expectedForecastId && !$forecast) {
                throw ValidationException::withMessages(['movement' => 'Esta previsão já foi liquidada ou deixou de estar disponível. Atualiza a página.']);
            }
            if ($forecast && (int) round((float) $forecast->amount * 100) !== (int) round($remaining * 100)) {
                throw ValidationException::withMessages(['movement' => 'A previsão não corresponde ao saldo da prestação. Revê o parcelamento antes de pagar.']);
            }
            if ($expectedForecastId && $amount < $remaining && !isset($data['expected_amount'])) {
                throw ValidationException::withMessages(['movement' => 'Atualiza a página antes de fazer um pagamento parcial.']);
            }
            if ($expectedForecastId && isset($data['expected_amount'])
                && (int) round((float) $data['expected_amount'] * 100) !== (int) round((float) $forecast->amount * 100)) {
                throw ValidationException::withMessages(['movement' => 'O valor pendente desta prestação foi alterado. Atualiza a página antes de pagar.']);
            }
            if ($forecast && $amount >= $remaining) {
                $payment = $this->movements->settle($user, $forecast, [
                    'source_account_id' => $paymentAccount->id,
                    'movement_date' => $data['payment_date'] ?? now()->toDateString(),
                ]);
            } else {
                if ($forecast) $forecast->update(['amount' => number_format($remaining - $amount, 2, '.', '')]);
                $payment = $this->movements->create($user, [
                    'type' => 'expense', 'description' => 'Pagamento: '.$lockedDebt->name,
                    'amount' => number_format($amount, 2, '.', ''),
                    'movement_date' => $data['payment_date'] ?? now()->toDateString(),
                    'source_account_id' => $paymentAccount->id, 'debt_id' => $debt->id, 'installment_id' => $locked->id,
                    'category_id' => $forecast?->category_id,
                ]);
            }
            $paid = round((float) $locked->paid_amount + $amount, 2);
            $locked->update(['paid_amount' => number_format($paid, 2, '.', ''), 'status' => $paid >= (float) $locked->amount ? 'paid' : 'partial']);
            if (!$lockedDebt->parcelas()->where('status', '!=', 'paid')->exists()) $lockedDebt->update(['status' => 'paid']);
            return ['installment' => $locked->refresh()->load('divida'), 'movement' => $payment];
        });
    }
}
