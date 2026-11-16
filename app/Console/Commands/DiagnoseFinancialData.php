<?php

namespace App\Console\Commands;

use App\Services\Financial\FinancialDataDiagnosticService;
use Illuminate\Console\Command;

class DiagnoseFinancialData extends Command
{
    protected $signature = 'financial:diagnose-data {--user= : UUID de um utilizador específico} {--json : Emitir o relatório em JSON}';
    protected $description = 'Diagnostica duplicações ambíguas, ligações legadas partidas e obrigações incompletas sem corrigir dados.';

    public function handle(FinancialDataDiagnosticService $diagnostic): int
    {
        $report = $diagnostic->report($this->option('user'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if ($report === []) {
            $this->warn('Nenhum utilizador encontrado.');
            return self::SUCCESS;
        }

        foreach ($report as $item) {
            $this->line('Utilizador: '.$item['user_id']);
            foreach ($item['counts'] as $name => $count) {
                $this->line('  '.$name.': '.$count);
            }
        }

        $this->info('Diagnóstico concluído. Nenhum dado foi alterado.');
        return self::SUCCESS;
    }
}
