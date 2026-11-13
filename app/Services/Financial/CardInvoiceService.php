<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use App\Models\Fatura;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardInvoiceService
{
    public function __construct(private readonly FinancialMovementService $movements) {}

    public function purchase(User $user, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $data): Movimento {
            $card = $user->contasFinanceiras()->whereKey($data['account_id'])->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona um cartão de crédito ativo.']);
            }
            if (!$card->closing_day || !$card->due_day) {
                throw ValidationException::withMessages(['account_id' => 'Configura primeiro os dias de fecho e vencimento do cartão.']);
            }
            if ($card->credit_limit !== null) {
                $balance = app(AccountBalanceService::class)->toCents(app(AccountBalanceService::class)->balance($card));
                $purchase = app(AccountBalanceService::class)->toCents((string) $data['amount']);
                if (max(0, -$balance) + $purchase > app(AccountBalanceService::class)->toCents((string) $card->credit_limit)) {
                    throw ValidationException::withMessages(['amount' => 'A compra excede o limite disponível do cartão.']);
                }
            }
            $date = Carbon::parse($data['movement_date'])->startOfDay();
            if ($date->greaterThan(today())) {
                throw ValidationException::withMessages(['movement_date' => 'A data da compra no cartão não pode ser futura.']);
            }
            $reference = $date->day > $card->closing_day ? $date->copy()->addMonthNoOverflow() : $date->copy();
            $reference->day = 1;
            $closing = $reference->copy()->day(min($card->closing_day, $reference->daysInMonth));
            $due = $reference->copy()->addMonthNoOverflow()->day(min($card->due_day, $reference->copy()->addMonthNoOverflow()->daysInMonth));
            $invoice = Fatura::query()->firstOrCreate(
                ['user_id' => $user->id, 'account_id' => $card->id, 'reference_month' => $reference->toDateString()],
                ['closing_date' => $closing->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open']
            );
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'open') {
                throw ValidationException::withMessages(['invoice' => 'A fatura deste ciclo já foi fechada.']);
            }
            return $this->movements->create($user, [
                'type' => 'expense', 'description' => $data['description'], 'amount' => $data['amount'],
                'movement_date' => $date->toDateString(), 'source_account_id' => $card->id,
                'category_id' => $data['category_id'] ?? null, 'invoice_id' => $invoice->id,
                'debt_id' => $data['debt_id'] ?? null, 'installment_id' => $data['installment_id'] ?? null,
                'legacy_type' => $data['legacy_type'] ?? null, 'legacy_id' => $data['legacy_id'] ?? null,
                'is_historical' => (bool) ($data['is_historical'] ?? false),
            ]);
        });
    }

    public function listInvoices(User $user)
    {
        return Fatura::query()->where('user_id', $user->id)
            ->whereHas('movimentos', fn ($q) => $q->where('status', 'settled'))
            ->with('conta:id,name,type,currency,credit_limit')
            ->with(['movimentos' => fn ($q) => $q->where('status', 'settled')->select('id', 'invoice_id', 'description', 'amount', 'movement_date')])
            ->withSum(['movimentos as total_amount' => fn ($q) => $q->where('status', 'settled')], 'amount')
            ->withCount(['movimentos' => fn ($q) => $q->where('status', 'settled')])
            ->orderByDesc('reference_month')->paginate(30);
    }

    public function pay(User $user, Fatura $invoice, string $sourceAccountId, string $date): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $sourceAccountId, $date): Movimento {
            $card = $user->contasFinanceiras()->whereKey($invoice->account_id)->lockForUpdate()->firstOrFail();
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já foi liquidada ou fechada.']);
            }
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->firstOrFail();
            if ($source->id === $card->id || !in_array($source->type, ['bank', 'cash'], true) || !$source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'A fatura deve ser paga a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            $cents = app(AccountBalanceService::class)->toCents((string) $locked->movimentos()->where('status', 'settled')->sum('amount'));
            if ($cents <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Não é possível liquidar uma fatura sem movimentos.']);
            }
            $amount = number_format($cents / 100, 2, '.', '');
            $movement = $this->movements->transfer($user, [
                'description' => 'Pagamento da fatura ' . $card->name . ' · ' . $locked->reference_month->format('m/Y'),
                'amount' => $amount, 'movement_date' => $date,
                'source_account_id' => $source->id, 'destination_account_id' => $card->id,
            ]);
            $locked->update(['status' => 'paid']);
            return $movement;
        });
    }
}
