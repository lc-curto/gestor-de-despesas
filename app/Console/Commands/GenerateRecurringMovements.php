<?php

namespace App\Console\Commands;

use App\Services\Financial\RecurrenceService;
use Illuminate\Console\Command;

class GenerateRecurringMovements extends Command
{
    protected $signature = 'financial:generate-recurring-movements';

    protected $description = 'Gera previsões de movimentos recorrentes até 90 dias.';

    public function handle(RecurrenceService $recurrences): int
    {
        $created = $recurrences->generateForAllUsers();
        $this->info("Previsões recorrentes criadas: {$created}");

        return self::SUCCESS;
    }
}
