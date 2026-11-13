<?php

namespace App\Services\Financial;

use App\Models\Divida;
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
                'installments_count' => $count, 'account_id' => $data['account_id'] ?? null,
                'total_amount' => $data['total_amount'], 'start_date' => $start->toDateString(),
                'due_date' => $data['due_date'] ?? $start->toDateString(), 'status' => 'open',
            ]);
            for ($i = 1; $i <= $count; $i++) {
                $amountCents = $base + ($i <= $remainder ? 1 : 0);
                ParcelaDivida::query()->create([
                    'user_id' => $user->id, 'debt_id' => $debt->id, 'installment_number' => $i,
                    'due_date' => Carbon::parse($data['due_date'] ?? $start)->addMonthsNoOverflow($i - 1)->toDateString(),
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                    'principal_amount' => number_format($amountCents / 100, 2, '.', ''),
                    'interest_amount' => '0.00', 'paid_amount' => '0.00', 'status' => 'pending',
                ]);
            }
            return $debt->load('parcelas');
        });
    }

    public function payInstallment(User $user, Divida $debt, ParcelaDivida $installment, array $data): ParcelaDivida
    {
        return DB::transaction(function () use ($user, $debt, $installment, $data): ParcelaDivida {
            $locked = ParcelaDivida::query()->where('user_id', $user->id)->where('debt_id', $debt->id)->whereKey($installment->id)->lockForUpdate()->firstOrFail();
            $paymentAccount = $user->contasFinanceiras()->whereKey($data['account_id'])->firstOrFail();
            if (!in_array($paymentAccount->type, ['bank', 'cash'], true) || !$paymentAccount->is_operational || $paymentAccount->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Uma parcela deve ser liquidada a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            $remaining = round((float) $locked->amount - (float) $locked->paid_amount, 2);
            $amount = round((float) ($data['amount'] ?? $remaining), 2);
            if ($remaining <= 0 || $amount <= 0 || $amount > $remaining) throw ValidationException::withMessages(['amount' => 'O valor do pagamento excede o saldo da parcela.']);
            $movementData = [
                'description' => 'Pagamento: ' . $debt->name,
                'amount' => number_format($amount, 2, '.', ''),
                'movement_date' => $data['payment_date'] ?? now()->toDateString(),
            ];
            $this->movements->create($user, $movementData + [
                'type' => 'expense',
                'source_account_id' => $paymentAccount->id,
                'debt_id' => $debt->id,
                'installment_id' => $locked->id,
            ]);
            $paid = round((float) $locked->paid_amount + $amount, 2);
            $locked->update(['paid_amount' => number_format($paid, 2, '.', ''), 'status' => $paid >= (float) $locked->amount ? 'paid' : 'partial']);
            if (!$debt->parcelas()->where('status', '!=', 'paid')->exists()) $debt->update(['status' => 'paid']);
            return $locked->refresh()->load('divida');
        });
    }
}
