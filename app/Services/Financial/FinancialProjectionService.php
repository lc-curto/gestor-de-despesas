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

        // O saldo de abertura precisa refletir a mesma projeção que foi aplicada
        // nos meses anteriores. Incluir apenas movimentos liquidados fazia o
        // saldo regressar ao saldo inicial das contas ao navegar entre meses,
        // ignorando despesas/receitas pendentes que já tinham sido projetadas.
        $prior = $user->movimentos()
            ->whereIn('type', ['income', 'expense', 'transfer', 'adjustment'])
            ->whereIn('status', ['pending', 'partial', 'settled'])
            ->where('is_historical', false)
            ->where('is_settlement_parent', false)
            ->where('movement_date', '<', $rangeStart->toDateString())
            ->where(function ($query) {
                $query->whereNull('debt_id')
                    ->orWhereExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('dividas')
                            ->whereColumn('dividas.id', 'movimentos.debt_id')
                            ->whereColumn('dividas.user_id', 'movimentos.user_id')
                            ->whereNull('dividas.archived_at')
                            ->whereNotIn('dividas.status', ['cancelled', 'paid']);
                    });
            })
            ->get(['type', 'status', 'amount', 'source_account_id', 'destination_account_id', 'adjustment_direction']);
        $openingBalance += $prior->sum(fn (Movimento $movement) => $this->cashDelta($movement, $accountTypes));

        // Uma obrigação parcialmente liquidada é representada pelos eventos
        // reais já pagos mais o saldo ainda pendente, tal como no período atual.
        $priorPartiallySettled = $user->movimentos()
            ->where('is_settlement_parent', true)
            ->whereNull('installment_id')
            ->whereNull('invoice_id')
            ->where('status', 'partial')
            ->where('is_historical', false)
            ->whereIn('type', ['expense', 'income'])
            ->where('movement_date', '<', $rangeStart->toDateString())
            ->with(['settlements' => fn ($query) => $query->where('status', 'settled')])
            ->get();

        foreach ($priorPartiallySettled as $original) {
            $netSettled = $original->settlements
                ->sum(fn (Movimento $event) => ($event->reversal_of_id ? -1 : 1) * (float) $event->amount);
            $remaining = round((float) $original->amount - $netSettled, 2);

            if ($remaining > 0) {
                $openingBalance += $original->type === 'income' ? $remaining : -$remaining;
            }
        }

        // Prestações vencidas em períodos anteriores já reduziram o saldo
        // projetado na respetiva data. Ao abrir um mês posterior, esse impacto
        // passa para o saldo inicial em vez de desaparecer da projeção.
        $priorInstallments = $user->parcelasDivida()
            ->with('divida')
            ->whereIn('status', ['pending', 'partial'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as linked_movements')
                ->whereColumn('linked_movements.installment_id', 'parcelas_divida.id')
                ->where('linked_movements.user_id', $user->id)
                ->where('linked_movements.status', 'pending')
                ->where('linked_movements.type', 'expense')
                ->where('linked_movements.is_historical', false))
            ->where('due_date', '<', $rangeStart->toDateString())
            ->whereHas('divida', fn ($query) => $query
                ->whereNull('archived_at')
                ->whereNotIn('status', ['cancelled', 'paid']))
            ->get()
            ->filter(fn ($installment) => $installment->divida
                && ! in_array($installment->divida->payment_method, ['credit', 'card'], true))
            ->values();

        foreach ($priorInstallments as $installment) {
            $remaining = round((float) $installment->amount - (float) $installment->paid_amount, 2);
            if ($remaining > 0) {
                $openingBalance -= $remaining;
            }
        }

        // Faturas com vencimento anterior ao período também fazem parte do
        // saldo acumulado. O pagamento é projetado uma única vez, pelo valor
        // ainda em falta, sem voltar a descontar compras feitas no cartão.
        $priorInvoices = Fatura::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'carried')
            ->where('due_date', '<', $rangeStart->toDateString())
            ->with([
                'conta',
                'movimentos' => fn ($query) => $query->where('status', 'settled'),
                'creditos' => fn ($query) => $query->where('status', 'settled')->select('id', 'invoice_id', 'amount', 'invoice_charge_type'),
                'saldosTransportados:id,user_id,source_invoice_id,target_invoice_id,amount',
            ])
            ->get();

        foreach ($priorInvoices as $invoice) {
            $total = (float) $invoice->movimentos
                ->where('type', 'expense')->where('is_settlement_parent', false)->sum('amount')
                + (float) $invoice->saldosTransportados->sum('amount')
                - (float) $invoice->creditos->sum('amount');
            $paid = (float) $invoice->movimentos->where('type', 'transfer')
                ->sum(fn (Movimento $movement) => $movement->reversal_of_id ? -(float) $movement->amount : (float) $movement->amount);
            $remaining = round($total - $paid, 2);

            if ($remaining > 0) {
                $openingBalance -= $remaining;
            }
        }

        $items = $user->movimentos()
            ->with('categoria:id,name')
            ->whereIn('type', ['income', 'expense', 'transfer', 'adjustment'])
            ->whereIn('status', ['pending', 'partial', 'settled'])
            ->where('is_historical', false)
            ->where('is_settlement_parent', false)
            // Previsões associadas a compromissos arquivados ou encerrados
            // não entram na Rota Financeira. Liquidações efetivas permanecem
            // visíveis porque são eventos independentes, sem debt_id.
            ->where(function ($query) {
                $query->whereNull('debt_id')
                    ->orWhereExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('dividas')
                            ->whereColumn('dividas.id', 'movimentos.debt_id')
                            ->whereColumn('dividas.user_id', 'movimentos.user_id')
                            ->whereNull('dividas.archived_at')
                            ->whereNotIn('dividas.status', ['cancelled', 'paid']);
                    });
            })
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
            ->with('categoria:id,name')
            ->where('is_settlement_parent', true)
            ->whereNull('installment_id')
            ->whereNull('invoice_id')
            ->where('status', 'partial')
            ->where('is_historical', false)
            ->whereIn('type', ['expense', 'income'])
            ->where('movement_date', '>=', $rangeStart->toDateString())
            ->where('movement_date', '<', $rangeEndExclusive)
            ->get();
        $displayItems = $items->values();

        foreach ($partiallySettled as $original) {
            $netSettled = $original->settlements()
                ->where('status', 'settled')
                ->get(['amount', 'reversal_of_id'])
                ->sum(fn (Movimento $event) => ($event->reversal_of_id ? -1 : 1) * (float) $event->amount);
            $remaining = round((float) $original->amount - $netSettled, 2);
            if ($remaining <= 0) {
                continue;
            }
            // Mantém os eventos reais separados para os cálculos de caixa,
            // mas prepara um único lançamento consolidado para a interface.
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

            $summary = $this->syntheticExpense(
                (string) $original->id,
                $original->description,
                (float) $original->amount,
                $original->movement_date,
                $original->expense_behavior ?? 'variable'
            );
            $summary->type = $original->type;
            $summary->status = 'partial';
            $summary->source_account_id = $original->source_account_id;
            $summary->destination_account_id = $original->destination_account_id;
            $summary->category_id = $original->category_id;
            $summary->recurrence_id = $original->recurrence_id;
            $summary->notes = $original->notes;
            $summary->setRelation('categoria', $original->categoria);
            $summary->setAttribute('paid_amount', $netSettled);
            $summary->setAttribute('remaining_amount', $remaining);
            $summary->setAttribute('is_partial_summary', true);
            $summary->setAttribute('projection_delta', $original->type === 'income' ? $remaining : -$remaining);
            $displayItems = $displayItems
                ->reject(fn (Movimento $item) => (string) ($item->settles_movement_id ?? '') === (string) $original->id
                    || (string) $item->id === 'settlement-'.$original->id)
                ->push($summary);
        }

        $installments = $user->parcelasDivida()
            ->with('divida')
            ->whereHas('divida')
            ->whereIn('status', ['pending', 'partial'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as linked_movements')
                ->whereColumn('linked_movements.installment_id', 'parcelas_divida.id')
                ->where('linked_movements.user_id', $user->id)
                ->where('linked_movements.status', 'pending')
                ->where('linked_movements.type', 'expense')
                ->where('linked_movements.is_historical', false))
            ->where('due_date', '>=', $rangeStart->toDateString())
            ->where('due_date', '<', $rangeEndExclusive)
            ->get()
            ->filter(fn ($installment) => $installment->divida
                && $installment->divida->archived_at === null
                && ! in_array($installment->divida->status, ['cancelled', 'paid'], true)
                && ! in_array($installment->divida->payment_method, ['credit', 'card'], true))
            ->values();
        foreach ($installments as $installment) {
            $remaining = round((float) $installment->amount - (float) $installment->paid_amount, 2);
            if ($remaining <= 0) {
                continue;
            }
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
            ->where('status', '!=', 'carried')
            ->where('due_date', '>=', $rangeStart->toDateString())
            ->where('due_date', '<', $rangeEndExclusive)
            ->with([
                'conta',
                'movimentos' => fn ($query) => $query->where('status', 'settled'),
                'creditos' => fn ($query) => $query->where('status', 'settled')->select('id', 'invoice_id', 'amount', 'invoice_charge_type'),
                'saldosTransportados:id,user_id,source_invoice_id,target_invoice_id,amount',
            ])
            ->get();
        foreach ($invoices as $invoice) {
            $total = (float) $invoice->movimentos
                ->where('type', 'expense')->where('is_settlement_parent', false)->sum('amount')
                + (float) $invoice->saldosTransportados->sum('amount')
                - (float) $invoice->creditos->sum('amount');
            $paid = (float) $invoice->movimentos->where('type', 'transfer')
                ->sum(fn (Movimento $movement) => $movement->reversal_of_id ? -(float) $movement->amount : (float) $movement->amount);
            $remaining = round($total - $paid, 2);
            if ($remaining <= 0) {
                continue;
            }
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

        // Obrigações vencidas são apresentadas separadamente: não entram em $items
        // e, por isso, não alteram o saldo diário do período consultado.
        $overdue = collect();

        $overdueInstallments = $user->parcelasDivida()
            ->with('divida')
            ->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $rangeStart->toDateString())
            ->whereHas('divida', fn ($query) => $query
                ->whereNull('archived_at')
                ->whereNotIn('status', ['cancelled', 'paid'])
                ->whereNotIn('payment_method', ['credit', 'card']))
            ->get();

        foreach ($overdueInstallments as $installment) {
            $remaining = round((float) $installment->amount - (float) $installment->paid_amount, 2);
            if ($remaining <= 0 || ! $installment->divida) {
                continue;
            }

            $overdueItem = $this->syntheticExpense(
                'overdue-debt-'.$installment->id,
                $installment->divida->name.($installment->divida->installments_count > 1
                    ? ' · Parcela '.$installment->installment_number.'/'.$installment->divida->installments_count
                    : ''),
                $remaining,
                $installment->due_date,
                'fixed'
            );
            $overdueItem->setAttribute('overdue', true);
            $overdue->push($overdueItem);
        }

        $overdueInvoices = Fatura::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'carried')
            ->where('due_date', '<', $rangeStart->toDateString())
            ->with([
                'conta',
                'movimentos' => fn ($query) => $query->where('status', 'settled'),
                'creditos' => fn ($query) => $query->where('status', 'settled')->select('id', 'invoice_id', 'amount', 'invoice_charge_type'),
                'saldosTransportados:id,user_id,source_invoice_id,target_invoice_id,amount',
            ])
            ->get();

        foreach ($overdueInvoices as $invoice) {
            $total = (float) $invoice->movimentos
                ->where('type', 'expense')->where('is_settlement_parent', false)->sum('amount')
                + (float) $invoice->saldosTransportados->sum('amount')
                - (float) $invoice->creditos->sum('amount');
            $paid = (float) $invoice->movimentos->where('type', 'transfer')
                ->sum(fn (Movimento $movement) => $movement->reversal_of_id ? -(float) $movement->amount : (float) $movement->amount);
            $remaining = round($total - $paid, 2);
            if ($remaining <= 0) {
                continue;
            }

            $overdueItem = $this->syntheticExpense(
                'overdue-invoice-'.$invoice->id,
                'Fatura vencida · '.($invoice->conta?->name ?? 'Cartão'),
                $remaining,
                $invoice->due_date,
                'fixed'
            );
            $overdueItem->setAttribute('overdue', true);
            $overdue->push($overdueItem);
        }

        return [
            'openingBalance' => round($openingBalance, 2),
            'movements' => $items->sortBy('movement_date')->values(),
            'displayMovements' => $displayItems->sortBy('movement_date')->values(),
            'overdue' => $overdue->sortBy('movement_date')->values(),
        ];
    }

    private function cashDelta(Movimento $movement, Collection|array $accountTypes): float
    {
        $delta = 0.0;
        $sourceType = $movement->source_account_id ? ($accountTypes[$movement->source_account_id] ?? null) : null;
        $destinationType = $movement->destination_account_id ? ($accountTypes[$movement->destination_account_id] ?? null) : null;
        $sourceIsCash = in_array($sourceType, ['bank', 'cash'], true);
        $sourceIsCreditCard = $sourceType === 'credit_card';
        $destinationIsCash = in_array($destinationType, ['bank', 'cash'], true);

        if ($movement->type === 'income' && $destinationIsCash) {
            $delta += (float) $movement->amount;
        }
        // Despesas pendentes sem conta de origem (previsões de prestações)
        // continuam projetadas. Apenas compras pendentes num cartão ficam
        // fora do caixa até ao pagamento da respetiva fatura.
        if ($movement->type === 'expense'
            && ($sourceIsCash || ($movement->status === 'pending' && ! $sourceIsCreditCard && $sourceType === null))) {
            $delta -= (float) $movement->amount;
        }
        if ($movement->type === 'transfer') {
            if ($sourceIsCash) {
                $delta -= (float) $movement->amount;
            }
            if ($destinationIsCash) {
                $delta += (float) $movement->amount;
            }
        }
        // Ajustes representam correções já liquidadas, não novas receitas ou despesas.
        if ($movement->type === 'adjustment' && $movement->status === 'settled') {
            if ($movement->adjustment_direction === 'increase' && $destinationIsCash) {
                $delta += (float) $movement->amount;
            }
            if ($movement->adjustment_direction === 'decrease' && $sourceIsCash) {
                $delta -= (float) $movement->amount;
            }
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
