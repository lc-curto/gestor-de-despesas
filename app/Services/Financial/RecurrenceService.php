<?php

namespace App\Services\Financial;

use App\Models\Categoria;
use App\Models\Despesa;
use App\Models\Recorrencia;
use App\Models\Receita;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurrenceService
{
    public const FORECAST_DAYS = 90;
    private const MAX_OCCURRENCES_PER_RUN = 500;

    public function __construct(private readonly FinancialMovementService $movements) {}

    public function create(User $user, array $data): Recorrencia
    {
        $category = Categoria::query()->where('user_id', $user->id)->whereKey($data['category_id'])->first();
        if (!$category || !$category->is_active || $category->type !== ($data['type'] === 'expense' ? 'despesa' : 'receita')) {
            throw ValidationException::withMessages(['category_id' => 'A categoria não corresponde ao tipo de movimento.']);
        }
        $this->assertDefaultAccount($user, $data['type'], $data['account_id'] ?? null);

        $rule = DB::transaction(function () use ($user, $data): Recorrencia {
            $rule = $user->recorrencias()->create([
                'type' => $data['type'],
                'expense_behavior' => $data['type'] === 'expense' ? ($data['expense_behavior'] ?? 'variable') : 'variable',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'category_id' => $data['category_id'],
                'account_id' => $data['account_id'] ?? null,
                'frequency' => $data['frequency'],
                'interval_value' => $data['interval_value'],
                'anchor_date' => $data['start_date'],
                'next_occurrence' => $data['start_date'],
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => true,
            ]);
            $this->generateRule($user, $rule->id);
            return $rule->fresh(['categoria', 'conta']);
        });

        return $rule;
    }


    public function syncEditedMovement(User $user, \App\Models\Movimento $movement, array $data, bool $enabled): void
    {
        DB::transaction(function () use ($user, $movement, $data, $enabled): void {
            $movement = $user->movimentos()->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $rule = $movement->recurrence_id ? $user->recorrencias()->whereKey($movement->recurrence_id)->lockForUpdate()->first() : null;
            if (!$enabled) {
                if ($rule) {
                    $rule->update(['is_active' => false]);
                    $date = Carbon::parse($movement->recurrence_occurrence_date ?: $movement->movement_date)->toDateString();
                    $forecasts = $user->movimentos()->where('recurrence_id', $rule->id)
                        ->where('status', 'pending')->whereDate('recurrence_occurrence_date', '>', $date)->lockForUpdate()->get()
                        ->filter(fn ($forecast) => !$forecast->historico()->where('action', 'confirmed')->exists());
                    foreach ($forecasts as $forecast) {
                        if ($forecast->legacy_type === 'despesa') Despesa::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                        if ($forecast->legacy_type === 'receita') Receita::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                        $forecast->historico()->delete();
                        $forecast->delete();
                    }
                }
                return;
            }
            $category = Categoria::query()->where('user_id', $user->id)->whereKey($data['category_id'])->first();
            if (!$category || !$category->is_active || $category->type !== ($movement->type === 'expense' ? 'despesa' : 'receita')) {
                throw ValidationException::withMessages(['category_id' => 'A categoria não corresponde ao tipo de movimento.']);
            }
            $this->assertDefaultAccount($user, $movement->type, $data['account_id'] ?? null);
            $date = Carbon::parse($movement->movement_date)->toDateString();
            $values = ['type' => $movement->type, 'expense_behavior' => $movement->type === 'expense' ? ($data['expense_behavior'] ?? 'variable') : 'variable', 'description' => $data['description'], 'amount' => $data['amount'], 'category_id' => $data['category_id'], 'account_id' => $data['account_id'] ?? null, 'frequency' => $data['frequency'], 'interval_value' => (int) $data['interval_value'], 'anchor_date' => $date, 'ends_at' => $data['ends_at'] ?? null, 'is_active' => true];
            if (!$rule) {
                $rule = $user->recorrencias()->create($values + ['next_occurrence' => $date]);
                $movement->recurrence_id = $rule->id;
                $movement->recurrence_occurrence_date = $date;
                $movement->save();
            } else {
                $forecasts = $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'pending')->whereDate('recurrence_occurrence_date', '>', $date)->lockForUpdate()->get()
                    ->filter(fn ($forecast) => !$forecast->historico()->where('action', 'confirmed')->exists());
                foreach ($forecasts as $forecast) {
                    if ($forecast->legacy_type === 'despesa') Despesa::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                    if ($forecast->legacy_type === 'receita') Receita::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                    $forecast->historico()->delete(); $forecast->delete();
                }
                $rule->fill($values); $rule->next_occurrence = $date; $rule->save();
                $movement->recurrence_occurrence_date = $date; $movement->save();
            }
            $rule->next_occurrence = $this->nextDate($rule, Carbon::parse($date))->toDateString(); $rule->save();
        });
        $ruleId = $user->movimentos()->whereKey($movement->id)->value('recurrence_id');
        if ($enabled && $ruleId) $this->generateRule($user, (string) $ruleId);
    }

    public function generateForUser(User $user): int
    {
        $created = 0;
        $ids = $user->recorrencias()->where('is_active', true)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $created += $this->generateRule($user, (string) $id);
        }
        return $created;
    }

    public function generateForAllUsers(): int
    {
        $created = 0;
        Recorrencia::query()->with('user')->where('is_active', true)->orderBy('id')->chunkById(100, function ($rules) use (&$created): void {
            foreach ($rules as $rule) {
                if ($rule->user) {
                    $created += $this->generateRule($rule->user, $rule->id);
                }
            }
        });
        return $created;
    }

    public function setActive(User $user, Recorrencia $recurrence, bool $active): Recorrencia
    {
        DB::transaction(function () use ($user, $recurrence, $active): void {
            $rule = Recorrencia::query()->where('user_id', $user->id)->whereKey($recurrence->id)->lockForUpdate()->firstOrFail();
            if ($active && Carbon::parse($rule->next_occurrence)->lt(today())) {
                $cursor = Carbon::parse($rule->next_occurrence);
                $steps = 0;
                while ($cursor->lt(today()) && $steps++ < self::MAX_OCCURRENCES_PER_RUN * 100) {
                    $cursor = $this->nextDate($rule, $cursor);
                }
                $rule->next_occurrence = $cursor->toDateString();
            }
            $rule->is_active = $active;
            $rule->save();

            // Pausar também elimina previsões futuras ainda não confirmadas.
            // Movimentos já liquidados permanecem intactos para preservar o histórico.
            if (!$active) {
                $forecasts = $user->movimentos()
                    ->where('recurrence_id', $rule->id)
                    ->where('status', 'pending')
                    ->whereDate('recurrence_occurrence_date', '>=', today()->toDateString())
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn ($forecast) => !$forecast->historico()->where('action', 'confirmed')->exists());

                foreach ($forecasts as $forecast) {
                    if ($forecast->legacy_type === 'despesa') {
                        Despesa::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                    }
                    if ($forecast->legacy_type === 'receita') {
                        Receita::query()->where('user_id', $user->id)->whereKey($forecast->legacy_id)->delete();
                    }
                    $forecast->historico()->delete();
                    $forecast->delete();
                }
            }
        });

        if ($active) {
            $this->generateRule($user, $recurrence->id);
        }

        return $user->recorrencias()->with(['categoria', 'conta'])->whereKey($recurrence->id)->firstOrFail();
    }

    private function generateRule(User $user, string $recurrenceId): int
    {
        return DB::transaction(function () use ($user, $recurrenceId): int {
            $rule = Recorrencia::query()->where('user_id', $user->id)->whereKey($recurrenceId)->lockForUpdate()->firstOrFail();
            if (!$rule->is_active) return 0;

            if ($rule->account_id) {
                $account = $user->contasFinanceiras()->whereKey($rule->account_id)->first();
                if (!$account || !$account->is_operational || $account->status !== 'active'
                    || $account->currency !== $user->currency
                    || ($account->type === 'credit_card' && (!$account->closing_day || !$account->due_day))) {
                    $rule->update(['is_active' => false]);
                    return 0;
                }
            }

            $category = Categoria::query()->where('user_id', $user->id)->whereKey($rule->category_id)->firstOrFail();
            $cursor = Carbon::parse($rule->next_occurrence)->startOfDay();
            $through = today()->addDays(self::FORECAST_DAYS);
            $created = 0;
            $processed = 0;

            while ($cursor->lte($through) && $processed < self::MAX_OCCURRENCES_PER_RUN) {
                if ($rule->ends_at && $cursor->gt(Carbon::parse($rule->ends_at))) break;
                $scheduledDate = $cursor->toDateString();
                $exists = $user->movimentos()
                    ->where('recurrence_id', $rule->id)
                    ->where('recurrence_occurrence_date', $scheduledDate)
                    ->exists();

                if (!$exists) {
                    $legacy = $this->createLegacyRecord($user, $rule, $category, $scheduledDate);
                    $this->movements->createPending($user, [
                        'type' => $rule->type,
                        'expense_behavior' => $rule->type === 'expense' ? $rule->expense_behavior : 'variable',
                        'description' => $rule->description,
                        'amount' => (string) $rule->amount,
                        'movement_date' => $scheduledDate,
                        'source_account_id' => $rule->type === 'expense' ? $rule->account_id : null,
                        'destination_account_id' => $rule->type === 'income' ? $rule->account_id : null,
                        'category_id' => $rule->category_id,
                        'recurrence_id' => $rule->id,
                        'recurrence_occurrence_date' => $scheduledDate,
                        'legacy_type' => $legacy['type'],
                        'legacy_id' => $legacy['id'],
                    ]);
                    $created++;
                }

                $cursor = $this->nextDate($rule, $cursor);
                $rule->next_occurrence = $cursor->toDateString();
                $rule->save();
                $processed++;
            }

            if ($rule->ends_at && $cursor->gt(Carbon::parse($rule->ends_at))) {
                $rule->is_active = false;
                $rule->save();
            }

            return $created;
        });
    }

    private function createLegacyRecord(User $user, Recorrencia $rule, Categoria $category, string $date): array
    {
        $legacyCategories = $rule->type === 'expense'
            ? ['casa', 'alimentacao', 'transportes', 'saude', 'lazer', 'compras', 'educacao', 'outros']
            : ['salario', 'vendas', 'trabalho_extra', 'investimentos', 'reembolsos', 'outros'];
        $legacyCategory = in_array($category->slug, $legacyCategories, true) ? $category->slug : 'outros';
        if ($rule->type === 'expense') {
            $legacy = Despesa::query()->create([
                'user_id' => $user->id,
                'description' => $rule->description,
                'amount' => $rule->amount,
                'currency' => $user->currency,
                'category' => $legacyCategory,
                'category_id' => $category->id,
                'expense_date' => $date,
            ]);
            return ['type' => 'despesa', 'id' => $legacy->id];
        }

        $legacy = Receita::query()->create([
            'user_id' => $user->id,
            'description' => $rule->description,
            'amount' => $rule->amount,
            'currency' => $user->currency,
            'category' => $legacyCategory,
            'category_id' => $category->id,
            'income_date' => $date,
        ]);
        return ['type' => 'receita', 'id' => $legacy->id];
    }

    private function nextDate(Recorrencia $rule, Carbon $date): Carbon
    {
        $interval = max(1, (int) $rule->interval_value);
        $anchor = Carbon::parse($rule->anchor_date ?: $rule->next_occurrence);

        return match ($rule->frequency) {
            'daily' => $date->copy()->addDays($interval),
            'weekly' => $date->copy()->addWeeks($interval),
            'monthly' => $this->monthAtAnchor($date, $anchor, $interval),
            'yearly' => $this->yearAtAnchor($date, $anchor, $interval),
            'custom' => $date->copy()->addDays($interval),
            default => throw ValidationException::withMessages(['frequency' => 'Frequência recorrente inválida.']),
        };
    }

    private function monthAtAnchor(Carbon $date, Carbon $anchor, int $interval): Carbon
    {
        $next = $date->copy()->startOfMonth()->addMonthsNoOverflow($interval);
        return $next->day(min($anchor->day, $next->daysInMonth));
    }

    private function yearAtAnchor(Carbon $date, Carbon $anchor, int $interval): Carbon
    {
        $next = $date->copy()->startOfYear()->addYears($interval)->month($anchor->month);
        return $next->day(min($anchor->day, $next->daysInMonth));
    }

    private function assertDefaultAccount(User $user, string $type, ?string $accountId): void
    {
        if (!$accountId) return;
        $account = $user->contasFinanceiras()->whereKey($accountId)->first();
        $allowedTypes = $type === 'expense' ? ['bank', 'cash', 'credit_card'] : ['bank', 'cash'];
        if (!$account || !$account->is_operational || $account->status !== 'active' || $account->currency !== $user->currency || !in_array($account->type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['account_id' => 'A conta predefinida não pode ser usada neste tipo de movimento.']);
        }
        if ($account->type === 'credit_card' && (!$account->closing_day || !$account->due_day)) {
            throw ValidationException::withMessages(['account_id' => 'Configura os dias de fecho e vencimento do cartão antes de criar uma recorrência.']);
        }
    }
}
