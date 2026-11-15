<?php

namespace App\Http\Controllers;

use App\Services\Financial\RecurrenceService;
use Illuminate\Http\Request;

class FinancialRecurrencesPageController extends Controller
{
    public function __invoke(Request $request, RecurrenceService $service)
    {
        $user = $request->user();
        $service->generateForUser($user);

        $recurrences = $user->recorrencias()
            ->with(['categoria', 'conta', 'movimentos' => fn ($query) => $query->where('status', 'pending')->orderBy('movement_date')])
            ->orderByDesc('is_active')->orderByDesc('created_at')->get();
        $occurrences = $user->movimentos()
            ->whereNotNull('recurrence_id')
            ->with(['recorrencia.conta', 'categoria', 'contaOrigem', 'contaDestino', 'historico'])
            ->orderByDesc('movement_date')->orderByDesc('created_at')->paginate(40);
        $accounts = $user->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash', 'credit_card'])
            ->where('is_operational', true)->where('status', 'active')->orderBy('name')->get();
        $categories = $user->categorias()->where('is_active', true)->orderBy('name')->get();

        return view('financial.recurrences', compact('recurrences', 'occurrences', 'accounts', 'categories'))
            ->with('currency', $user->currency);
    }
}
