<?php

namespace App\Services\Financial;

use App\Models\Fatura;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialProjectionService
{
    /**
     * Produz o saldo inicial de caixa e os eventos que devem aparecer na rota.
     * Cartões não entram no saldo de caixa; pagamentos de fatura saem da conta
     * bancária e prestações futuras representam apenas o valor ainda em falta.
     */
    public function build(User $user, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $cashAccounts = $user->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->get(['id', 'type', 'opening_balance']);
        $accountTypes = $user->contasFinanceiras()
            ->where('is_operational', true)
            ->where('status', 'active')
            ->pluck('type', 'id');
        // O limite exclusivo inclui também datas guardadas com hora pelo SQLite.
        $rangeEndExclusive = $rangeEnd->copy()->addDay()->toDateString();

        $openingBalance = (float) $cashAccounts->sum('opening_balance');
        $prior = $user->movimentos()
            ->where('status', 'settled')
            ->where('is_historical', false)
            ->where('movement_date', '<', $rangeStart->toDateString())
            ->get(['type', 'status', 'amount', 'source_account_id', 'destination_account_id', 'adjustment_direction']);
        $openingBalance += $prior->sum(fn (Movimento $movement) => $this->cashDelta($movement, $accountTypes));

        $items = $user->movimentos()
            ->with('categoria:id,name')
            ->whereIn('type', ['income', 'expense', 'transfer', 'adjustment'])
            ->whereIn('status', ['pending', 'partial', 'settled'])
            ->where('is_historical', false)
            ->where('is_settlement_parent', false)
            ->where('movement_date', '>=', $rangeStart->toDateString())
            ->where('movement_date', '<', $rangeEndExclusive)
            ->orderBy('movement_date')
            ->get(['id', 'type', 'status', 'description', 'notes', 'amount', 'movement_date', 'expense_behavior', 'category_id', 'source_account_id', 'destination_account_id', 'recurrence_id', 'adjustment_direction', 'settles_movement_id']);

        $items = $items->map(function (Movimento $movement) use ($accountTypes): Movimento {
            $movement->setAttribute('projection_delta', $this->cashDelta($movement, $accountTypes));
            return $movement;
        })->filter(fn (Movimento $movement) => (float) $movement->projection_delta !== 0.0)->values();

        // Uma obrigação parcialmente liquidada projeta apenas o saldo não pago.
        $partiallySettled = $user->movimentos()
            ->where('is_settlement_parent', true)
            ->where('status', 'partial')
            ->where('is_historical', false)
            ->whereIn('type', ['expense', 'income'])
            ->where('movement_date', '>=', $rangeStart->toDateString())
            ->where('movement_date', '<', $rangeEndExclusive)
            ->get();
        foreach ($partiallySettled as $original) {
            $netSettled = $original->settlements()
                ->where('status', 'settled')
                ->get(['amount', 'reversal_of_id'])
                ->sum(fn (Movimento $event) => ($event->reversal_of_id ? -1 : 1) * (float) $event->amount);
            $remaining = round((float) $original->amount - $netSettled, 2);
            if ($remaining <= 0) continue;
            $synthetic = $this->syntheticExpense(
                'settlement-'.$original->id,
                $original->description.' · Saldo pendente',
                $remaining,
                $original->movement_date,
                $original->expense_behavior ?? 'variable'
            );
            $synthetic->type = $original->type;
            $synthetic->source_account_id = $original->source_account_id;
            $synthetic->destination_account_id = $original->destination_account_id;
            $synthetic->setAttribute('projection_delta', $original->type === 'income' ? $remaining : -$remaining);
            $items->push($synthetic);
        }

        $installments = $user->parcelasDivida()
            ->with('divida')
            ->whereHas('divida', fn ($query) => $query
                // Projetar apenas parcelamentos ativos e visíveis na gestão de Parcelamentos.
                // Compras de pagamento único e compromissos arquivados não são projetáveis.
                ->whereNull('archived_at')
                ->whereNotIn('status', ['cancelled', 'paid'])
                ->where(fn ($query) => $query
                    ->whereNull('payment_method')
                    ->orWhereNotIn('payment_method', ['credit', 'card'])))
            ->whereIn('status', ['pending', 'partial'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as linked_movements')
                ->whereColumn('linked_movements.installment_id', 'parcelas_divida.id')
                ->where('linked_movements.user_id', $user->id)
                ->where('linked_movements.status', 'pending')
                ->where('linked_movements.type', 'expense')
                ->where('linked_movements.is_historical', false))
            ->where('due_date', '>=', $rangeStart->toDateString())
            ->where('due_date', '<', $rangeEndExclusive)
            ->get();
        foreach ($installments as $installment) {
            $remaining = round((float) $installment->amount - (float) $installment->paid_amount, 2);
            if ($remaining <= 0) continue;
            $synthetic = $this->syntheticExpense(
                'debt-'.$installment->id,
                $installment->divida->name.' · Parcela '.$installment->installment_number.'/'.$installment->divida->installments_count,
                $remaining,
                $installment->due_date,
                'variable'
            );
            $synthetic->setAttribute('projection_delta', -$remaining);
            $items->push($synthetic);
        }

        $invoices = Fatura::query()
            ->where('user_id', $user->id)
            ->where('due_date', '>=', $rangeStart->toDateString())
            ->where('due_date', '<', $rangeEndExclusive)
            ->with(['conta', 'movimentos' => fn ($query) => $query->where('status', 'settled')])
            ->get();
        foreach ($invoices as $invoice) {
            $total = (float) $invoice->movimentos->where('type', 'expense')->sum('amount');
            $paid = (float) $invoice->movimentos->where('type', 'transfer')->sum('amount');
            $remaining = round($total - $paid, 2);
            if ($remaining <= 0) continue;
            $synthetic = $this->syntheticExpense(
                'invoice-'.$invoice->id,
                'Pagamento de fatura · '.($invoice->conta?->name ?? 'Cartão'),
                $remaining,
                $invoice->due_date,
                'fixed'
            );
            $synthetic->setAttribute('projection_delta', -$remaining);
            $items->push($synthetic);
        }

        return [
            'openingBalance' => round($openingBalance, 2),
            'movements' => $items->sortBy('movement_date')->values(),
        ];
    }

    private function cashDelta(Movimento $movement, Collection|array $accountTypes): float
    {
        $delta = 0.0;
        $sourceType = $movement->source_account_id ? ($accountTypes[$movement->source_account_id] ?? null) : null;
        $destinationType = $movement->destination_account_id ? ($accountTypes[$movement->destination_account_id] ?? null) : null;
        $sourceIsCash = in_array($sourceType, ['bank', 'cash'], true);
        $destinationIsCash = in_array($destinationType, ['bank', 'cash'], true);

        if ($movement->type === 'income' && $destinationIsCash) $delta += (float) $movement->amount;
        if ($movement->type === 'expense' && ($movement->status === 'pending' || $sourceIsCash)) $delta -= (float) $movement->amount;
        if ($movement->type === 'transfer') {
            if ($sourceIsCash) $delta -= (float) $movement->amount;
            if ($destinationIsCash) $delta += (float) $movement->amount;
        }
        // Ajustes representam correções já liquidadas, não novas receitas ou despesas.
        if ($movement->type === 'adjustment' && $movement->status === 'settled') {
            if ($movement->adjustment_direction === 'increase' && $destinationIsCash) $delta += (float) $movement->amount;
            if ($movement->adjustment_direction === 'decrease' && $sourceIsCash) $delta -= (float) $movement->amount;
        }

        return round($delta, 2);
    }

    private function syntheticExpense(string $id, string $description, float $amount, Carbon|string $date, string $behavior): Movimento
    {
        $movement = new Movimento([
            'type' => 'expense',
            'status' => 'pending',
            'description' => $description,
            'amount' => number_format($amount, 2, '.', ''),
            'movement_date' => Carbon::parse($date)->toDateString(),
            'expense_behavior' => $behavior,
            'source_account_id' => null,
            'destination_account_id' => null,
            'category_id' => null,
            'recurrence_id' => null,
        ]);
        // O id não é preenchível em massa no modelo, mas identifica eventos virtuais na rota.
        $movement->setAttribute('id', $id);
        return $movement;
    }
}
