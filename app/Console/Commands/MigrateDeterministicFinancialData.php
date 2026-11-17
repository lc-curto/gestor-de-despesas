<?php

namespace App\Console\Commands;

use App\Services\Financial\FinancialDataMigrationService;
use Illuminate\Console\Command;

class MigrateDeterministicFinancialData extends Command
{
    protected $signature = 'financial:migrate-deterministic-data {--user= : UUID de um utilizador específico} {--apply : Aplicar os casos determinísticos; sem esta opção apenas apresenta o plano} {--json : Emitir o relatório em JSON}';

    protected $description = 'Apresenta ou aplica reparações financeiras determinísticas; nunca elimina dados e mantém casos ambíguos para revisão.';

    public function handle(FinancialDataMigrationService $migration): int
    {
        $report = $this->option('apply')
            ? $migration->migrate($this->option('user'))
            : $migration->plan($this->option('user'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($report as $item) {
            $this->line('Utilizador: '.$item['user_id']);
            if (isset($item['applied_count'])) {
                $this->line('  reparações aplicadas: '.$item['applied_count']);
                $this->line('  casos ambíguos encaminhados para revisão: '.count($item['ambiguous']));
            } else {
                $this->line('  reparações determinísticas disponíveis: '.$item['counts']['deterministic']);
                $this->line('  casos ambíguos para revisão: '.$item['counts']['ambiguous']);
            }
            foreach ($item['ambiguous'] as $case) {
                $this->warn('  '.json_encode($case, JSON_UNESCAPED_UNICODE));
            }
        }

        $this->info($this->option('apply')
            ? 'Migração não destrutiva concluída. Nenhum caso ambíguo foi alterado.'
            : 'Plano apresentado; nenhum dado foi alterado. Use --apply apenas após rever o relatório.');

        return self::SUCCESS;
    }
}
