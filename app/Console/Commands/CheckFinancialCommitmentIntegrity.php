<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckFinancialCommitmentIntegrity extends Command
{
    protected $signature = 'financial:check-commitments {--user= : Limitar a verificação ao UUID de um utilizador}';

    protected $description = 'Audita relações entre movimentos, parcelamentos e prestações sem alterar dados.';

    public function handle(): int
    {
        $query = DB::table('movimentos as m')
            ->leftJoin('dividas as d', function ($join): void {
                $join->on('d.id', '=', 'm.debt_id')->on('d.user_id', '=', 'm.user_id');
            })
            ->leftJoin('parcelas_divida as p', function ($join): void {
                $join->on('p.id', '=', 'm.installment_id')->on('p.user_id', '=', 'm.user_id');
            })
            ->leftJoin('dividas as pd', function ($join): void {
                $join->on('pd.id', '=', 'p.debt_id')->on('pd.user_id', '=', 'p.user_id');
            })
            ->where('m.status', '!=', 'void')
            ->where(function ($relations): void {
                $relations->whereNotNull('m.debt_id')->orWhereNotNull('m.installment_id');
            })
            ->select([
                'm.id',
                'm.user_id',
                'm.debt_id',
                'm.installment_id',
                'm.status as movement_status',
                'd.id as existing_debt_id',
                'd.status as debt_status',
                'd.archived_at',
                'd.deleted_at',
                'p.id as existing_installment_id',
                'p.debt_id as installment_debt_id',
                'p.status as installment_status',
                'pd.id as installment_parent_exists',
                'pd.status as installment_parent_status',
                'pd.archived_at as installment_parent_archived_at',
                'pd.deleted_at as installment_parent_deleted_at',
            ])
            ->when($this->option('user'), fn ($builder, $userId) => $builder->where('m.user_id', $userId));

        $rows = $query->get();

        $issues = [
            'Movimentos com parcelamento inexistente' => $rows->filter(fn ($row) => $row->debt_id !== null && $row->existing_debt_id === null),
            'Movimentos com prestação inexistente' => $rows->filter(fn ($row) => $row->installment_id !== null && $row->existing_installment_id === null),
            'Prestações ligadas a parcelamentos inexistentes' => $rows->filter(fn ($row) => $row->existing_installment_id !== null
                && $row->installment_parent_exists === null),
            'Relações movimento/prestação divergentes' => $rows->filter(fn ($row) => $row->installment_id !== null
                && $row->existing_installment_id !== null && $row->debt_id !== $row->installment_debt_id),
            'Movimentos ligados a parcelamentos arquivados/eliminados' => $rows->filter(fn ($row) =>
                $row->archived_at !== null || $row->deleted_at !== null
                || $row->installment_parent_archived_at !== null || $row->installment_parent_deleted_at !== null),
            'Movimentos ligados a parcelamentos/prestações cancelados' => $rows->filter(fn ($row) => $row->debt_status === 'cancelled'
                || $row->installment_status === 'cancelled' || $row->installment_parent_status === 'cancelled'),
        ];

        $total = 0;
        foreach ($issues as $label => $items) {
            $count = $items->count();
            $total += $count;
            $this->line(sprintf('%s: <comment>%d</comment>', $label, $count));
            if ($count > 0) {
                $this->table(
                    ['Movimento', 'Utilizador', 'Debt ID', 'Installment ID', 'Estado'],
                    $items->take(50)->map(fn ($row) => [
                        $row->id,
                        $row->user_id,
                        $row->debt_id ?? '—',
                        $row->installment_id ?? '—',
                        $row->movement_status,
                    ])->all()
                );
                if ($count > 50) {
                    $this->warn('A apresentar apenas os primeiros 50 registos desta categoria.');
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('Verificação concluída. %d ocorrências encontradas. Nenhum dado foi alterado.', $total));

        return self::SUCCESS;
    }
}
