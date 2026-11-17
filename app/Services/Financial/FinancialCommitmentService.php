<?php

namespace App\Services\Financial;

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
            $count = (int) ($data['installments_count'] ?? 1);
            if ($condition === 'installments' && $count < 2) throw ValidationException::withMessages(['installments_count' => 'Um compromisso parcelado precisa de pelo menos 2 parcelas.']);
            if ($condition !== 'installments') $count = 1;
            $start = Carbon::parse($data['start_date']);
            $totalCents = (int) round(((float) $data['total_amount']) * 100);
            $base = intdiv($totalCents, $count);
            $remainder = $totalCents % $count;
            $debt = Divida::query()->create([
                'user_id' => $user->id, 'name' => $data['name'], 'type' => 'purchase',
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
            if (in_array($locked->status, ['cancelled', 'paid'], true)) throw ValidationException::withMessages(['commitment' => 'Este compromisso já está encerrado.']);
            $firstPending = $locked->parcelas()->whereIn('status', ['pending', 'partial'])->orderBy('installment_number')->first();
            $oldDue = $firstPending ? Carbon::parse($firstPending->due_date) : Carbon::parse($locked->due_date);
            $firstNumber = $firstPending?->installment_number ?? 1;
            $newDue = Carbon::parse($data['due_date']);
            $locked->update(['name' => $data['name'], 'due_date' => $newDue->toDateString()]);
            foreach ($locked->parcelas()->whereIn('status', ['pending', 'partial'])->get() as $part) {
                $offset = max(0, $part->installment_number - $firstNumber);
                $part->update(['due_date' => $this->installmentDate($newDue->copy(), $offset, $locked->installment_frequency ?? 'monthly')->toDateString()]);
            }
            return $locked->refresh()->load('parcelas');
        });
    }

    public function cancel(User $user, Divida $debt): Divida
    {
        return DB::transaction(function () use ($user, $debt): Divida {
            $locked = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'paid'], true)) throw ValidationException::withMessages(['commitment' => 'Este compromisso já está encerrado.']);
            $locked->parcelas()->whereIn('status', ['pending', 'partial'])->update(['status' => 'cancelled']);
            Movimento::query()->where('user_id', $user->id)->where('debt_id', $locked->id)->where('status', 'pending')->get()
                ->each(fn (Movimento $movement) => $this->movements->voidPending($user, $movement, 'Previsão anulada ao encerrar o parcelamento.'));
            $locked->update(['status' => 'cancelled']);
            return $locked->refresh();
        });
    }

    public function payInstallment(User $user, Divida $debt, ParcelaDivida $installment, array $data): ParcelaDivida
    {
        return DB::transaction(function () use ($user, $debt, $installment, $data): ParcelaDivida {
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
            $forecast = Movimento::query()->where('user_id', $user->id)->where('installment_id', $locked->id)->where('status', 'pending')->first();
            if ($forecast && $amount >= $remaining) {
                $this->movements->settle($user, $forecast, [
                    'source_account_id' => $paymentAccount->id,
                    'movement_date' => $data['payment_date'] ?? now()->toDateString(),
                ]);
            } else {
                if ($forecast) $forecast->update(['amount' => number_format($remaining - $amount, 2, '.', '')]);
                $this->movements->create($user, [
                    'type' => 'expense', 'description' => 'Pagamento: '.$lockedDebt->name,
                    'amount' => number_format($amount, 2, '.', ''),
                    'movement_date' => $data['payment_date'] ?? now()->toDateString(),
                    'source_account_id' => $paymentAccount->id, 'debt_id' => $debt->id, 'installment_id' => $locked->id,
                ]);
            }
            $paid = round((float) $locked->paid_amount + $amount, 2);
            $locked->update(['paid_amount' => number_format($paid, 2, '.', ''), 'status' => $paid >= (float) $locked->amount ? 'paid' : 'partial']);
            if (!$lockedDebt->parcelas()->where('status', '!=', 'paid')->exists()) $lockedDebt->update(['status' => 'paid']);
            return $locked->refresh()->load('divida');
        });
    }
}
