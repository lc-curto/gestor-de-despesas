<?php

namespace App\Services\Financial;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fonte única de leitura para os movimentos tradicionais (receitas e despesas).
 * Dashboard e Rota Financeira usam exatamente a mesma união e os mesmos filtros.
 */
class DashboardMovementQuery
{
    public function build(
        string $userId,
        Carbon $inicio,
        Carbon $fim,
        string $tipo,
        string $categoria
    ): Builder {
        $despesas = DB::table('despesas')
            ->join('categorias', 'categorias.id', '=', 'despesas.category_id')
            ->leftJoin('movimentos as financial_movements', function ($join) use ($userId) {
                $join->on('financial_movements.legacy_id', '=', 'despesas.id')
                    ->where('financial_movements.legacy_type', '=', 'despesa')
                    ->where('financial_movements.user_id', '=', $userId);
            })
            ->leftJoin('recorrencias as recurrence_rules', 'recurrence_rules.id', '=', 'financial_movements.recurrence_id')
            ->where('despesas.user_id', $userId)
            ->whereBetween('despesas.expense_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(fn ($q) => $q->whereNull('financial_movements.status')->orWhere('financial_movements.status', '!=', 'void'))
            ->selectRaw("despesas.id, financial_movements.id AS financial_movement_id, 'despesa' AS tipo, despesas.description, despesas.amount, despesas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, despesas.expense_date AS data_movimento, financial_movements.source_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical, financial_movements.expense_behavior AS expense_behavior, financial_movements.recurrence_id AS recurrence_id, recurrence_rules.frequency AS recurrence_frequency, recurrence_rules.interval_value AS recurrence_interval, recurrence_rules.ends_at AS recurrence_ends_at");

        $receitas = DB::table('receitas')
            ->join('categorias', 'categorias.id', '=', 'receitas.category_id')
            ->leftJoin('movimentos as financial_movements', function ($join) use ($userId) {
                $join->on('financial_movements.legacy_id', '=', 'receitas.id')
                    ->where('financial_movements.legacy_type', '=', 'receita')
                    ->where('financial_movements.user_id', '=', $userId);
            })
            ->leftJoin('recorrencias as recurrence_rules', 'recurrence_rules.id', '=', 'financial_movements.recurrence_id')
            ->where('receitas.user_id', $userId)
            ->whereBetween('receitas.income_date', [$inicio->toDateString(), $fim->toDateString()])
            ->where(fn ($q) => $q->whereNull('financial_movements.status')->orWhere('financial_movements.status', '!=', 'void'))
            ->selectRaw("receitas.id, financial_movements.id AS financial_movement_id, 'receita' AS tipo, receitas.description, receitas.amount, receitas.currency, categorias.id AS category, categorias.name AS category_label, categorias.icon AS category_icon, categorias.color AS category_color, receitas.income_date AS data_movimento, financial_movements.destination_account_id AS account_id, financial_movements.status AS movement_status, financial_movements.requires_account_assignment AS requires_account_assignment, financial_movements.is_historical AS is_historical, financial_movements.expense_behavior AS expense_behavior, financial_movements.recurrence_id AS recurrence_id, recurrence_rules.frequency AS recurrence_frequency, recurrence_rules.interval_value AS recurrence_interval, recurrence_rules.ends_at AS recurrence_ends_at");

        if ($tipo === 'despesa') {
            $query = $despesas;
        } elseif ($tipo === 'receita') {
            $query = $receitas;
        } else {
            $query = $despesas->unionAll($receitas);
        }

        $wrapped = DB::query()->fromSub($query, 'movimentos');
        if ($categoria !== 'all') {
            $wrapped->where('category', $categoria);
        }

        return $wrapped->orderByDesc('data_movimento')->orderBy('id');
    }
}
