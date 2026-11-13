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
    public function create(User $user, array $data): Divida
    {
        return DB::transaction(function () use ($user, $data): Divida {
            $condition = $data['payment_condition'];
            $count = (int) ($data['installments_count'] ?? 1);
            if ($condition === 'installments' && $count < 2) {
                throw ValidationException::withMessages(['installments_count' => 'Um compromisso parcelado precisa de pelo menos 2 parcelas.']);
            }
            if ($condition !== 'installments') $count = 1;
            $start = Carbon::parse($data['start_date']);
            $totalCents = (int) round(((float) $data['total_amount']) * 100);
            $base = intdiv($totalCents, $count);
            $remainder = $totalCents % $count;
            $debt = Divida::query()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'type' => 'purchase',
                'payment_condition' => $condition,
                'payment_method' => $data['payment_method'] ?? null,
                'installments_count' => $count,
                'account_id' => $data['account_id'] ?? null,
                'total_amount' => $data['total_amount'],
                'start_date' => $start->toDateString(),
                'due_date' => $data['due_date'] ?? $start->toDateString(),
                'status' => 'open',
            ]);
            for ($i = 1; $i <= $count; $i++) {
                $amountCents = $base + ($i <= $remainder ? 1 : 0);
                ParcelaDivida::query()->create([
                    'user_id' => $user->id,
                    'debt_id' => $debt->id,
                    'installment_number' => $i,
                    'due_date' => Carbon::parse($data['due_date'] ?? $start)->addMonths($i - 1)->toDateString(),
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                    'principal_amount' => number_format($amountCents / 100, 2, '.', ''),
                    'interest_amount' => '0.00',
                    'paid_amount' => '0.00',
                    'status' => 'pending',
                ]);
            }
            return $debt->load('parcelas');
        });
    }
}
