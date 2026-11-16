@extends('layouts.app')

@section('title', 'Recorrências')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Recorrências</h1>
            <p class="text-secondary mb-0">Controla as regras que criam previsões automáticas de despesas e receitas.</p>
        </div>
        <a href="{{ route('dashboard.movimentos') }}" class="btn btn-outline-secondary">Voltar aos movimentos</a>
    </div>

    <div class="alert alert-info border-0 rounded-3">
        <strong>Como funciona:</strong> uma recorrência é uma regra (por exemplo, Luz todos os meses).
        O sistema cria previsões até 90 dias à frente. Pausar interrompe novas previsões e remove as futuras ainda pendentes.
        Movimentos já liquidados não são apagados.
    </div>

    @forelse ($recurrences as $recurrence)
        <article class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="min-w-0">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <h2 class="h6 fw-bold mb-0">{{ $recurrence->description }}</h2>
                        <span class="badge {{ $recurrence->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ $recurrence->is_active ? 'Ativa' : 'Pausada' }}
                        </span>
                        <span class="badge {{ $recurrence->type === 'expense' ? 'text-bg-danger' : 'text-bg-success' }}">
                            {{ $recurrence->type === 'expense' ? 'Despesa' : 'Receita' }}
                        </span>
                    </div>
                    <p class="mb-1 fw-semibold">{{ number_format((float) $recurrence->amount, 2, ',', '.') }} {{ $recurrence->conta?->currency ?? auth()->user()->currency }}</p>
                    <p class="small text-secondary mb-0">
                        {{ $recurrence->frequency === 'daily' ? 'A cada dia(s)' : ($recurrence->frequency === 'weekly' ? 'A cada semana(s)' : ($recurrence->frequency === 'yearly' ? 'A cada ano(s)' : ($recurrence->frequency === 'custom' ? 'A cada ' . $recurrence->interval_value . ' dia(s)' : 'A cada mês(es)'))) }}
                        @if ($recurrence->frequency !== 'custom' && $recurrence->interval_value > 1) · Intervalo {{ $recurrence->interval_value }} @endif
                        · Próxima ocorrência: {{ $recurrence->next_occurrence?->format('d/m/Y') ?? '—' }}
                        @if ($recurrence->ends_at) · Termina em {{ $recurrence->ends_at->format('d/m/Y') }} @endif
                        @if ($recurrence->categoria) · {{ $recurrence->categoria->name }} @endif
                        @if ($recurrence->conta) · {{ $recurrence->conta->name }} @endif
                    </p>
                </div>
                <form method="POST" action="{{ route('financial.recurrences.status', $recurrence->id) }}" class="recurrence-status-form">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="is_active" value="{{ $recurrence->is_active ? '0' : '1' }}">
                    <button type="submit" class="btn {{ $recurrence->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                        {{ $recurrence->is_active ? 'Pausar recorrência' : 'Retomar recorrência' }}
                    </button>
                </form>
            </div>
        </article>
    @empty
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body text-center py-5">
                <h2 class="h5 fw-bold">Ainda não tens recorrências</h2>
                <p class="text-secondary mb-0">Ao ativares “Repetir esta despesa/receita” num movimento, a regra aparecerá aqui.</p>
            </div>
        </div>
    @endforelse
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.recurrence-status-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'A atualizar...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || 'Não foi possível atualizar a recorrência.');
            window.location.reload();
        } catch (error) {
            alert(error.message);
            button.disabled = false;
            button.textContent = originalText;
        }
    });
});
</script>
@endpush