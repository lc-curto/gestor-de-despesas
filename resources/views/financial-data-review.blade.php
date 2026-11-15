@extends('layouts.app')

@section('title', 'Revisão dos dados')

@section('content')
<div class="container-fluid py-3 py-md-4" style="max-width: 900px">
    <div class="mb-4">
        <span class="small fw-bold text-uppercase text-secondary" style="letter-spacing:.1em">Atualização do Meu Bolso</span>
        <h1 class="h2 fw-bold mt-2 mb-2">Vamos preparar os teus dados</h1>
        <p class="text-secondary mb-0">A nova versão tem uma estrutura financeira mais completa. Revê os dados existentes antes de continuar. O processo guarda o teu progresso e não elimina movimentos.</p>
    </div>

    @php
        $steps = [
            ['id' => 'accounts', 'title' => 'Contas financeiras', 'description' => 'Confirma que as contas existentes estão corretas e que os saldos iniciais representam a situação na data indicada.', 'detail' => $counts['accounts'].' contas financeiras encontradas.'],
            ['id' => 'categories', 'title' => 'Categorias', 'description' => 'Revê os nomes, tipos e estado das categorias. Confirma que receitas e despesas estão classificadas corretamente.', 'detail' => $counts['categories'].' categorias encontradas.'],
            ['id' => 'movements', 'title' => 'Movimentos existentes', 'description' => 'Confirma que os movimentos históricos estão completos. Presta atenção a datas, valores, contas, categorias e possíveis duplicados.', 'detail' => $counts['movements'].' movimentos no núcleo financeiro; '.$counts['legacy_expenses'].' despesas e '.$counts['legacy_incomes'].' receitas legadas.'],
            ['id' => 'classification', 'title' => 'Despesas fixas e variáveis', 'description' => 'Confirma a natureza de cada despesa. Fixa significa estrutural ou regular; variável corresponde a gastos que não fazem parte dos compromissos regulares.', 'detail' => 'A classificação alimenta os cálculos da Rota Financeira. Não assumas que uma despesa recorrente é automaticamente fixa.'],
            ['id' => 'final', 'title' => 'Conferência final', 'description' => 'Confirma que reveste os dados e que estás preparado para utilizar a nova versão.', 'detail' => 'A confirmação termina o bloqueio de acesso. As tuas alterações e os dados históricos serão preservados.'],
        ];
        $stepCount = count(array_intersect($completed, ['accounts','categories','movements','classification']));
    @endphp

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="small">Progresso da revisão</strong>
                <span class="small text-secondary">{{ $stepCount }} de 4 etapas</span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="{{ $stepCount * 25 }}" aria-valuemin="0" aria-valuemax="100" style="height:8px">
                <div class="progress-bar" style="width:{{ $stepCount * 25 }}%;background:#635bff"></div>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger rounded-3">{{ $errors->first() }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success rounded-3">{{ session('success') }}</div>
    @endif

    <div class="d-grid gap-3">
        @foreach ($steps as $index => $step)
            @php
                $done = in_array($step['id'], $completed, true);
                $locked = $step['id'] === 'final' && $stepCount < 4;
            @endphp
            <section class="card rounded-4 {{ $done ? 'border-success' : 'border' }}">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 {{ $done ? 'bg-success-subtle text-success' : 'bg-body-secondary' }}" style="width:38px;height:38px">
                            @if ($done)
                                <i data-lucide="check" aria-hidden="true"></i>
                            @else
                                <span class="fw-bold">{{ $index + 1 }}</span>
                            @endif
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <h2 class="h6 fw-bold mb-0">{{ $step['title'] }}</h2>
                                @if ($done)
                                    <span class="badge text-bg-success">Concluída</span>
                                @elseif ($locked)
                                    <span class="badge text-bg-secondary">Bloqueada</span>
                                @else
                                    <span class="badge text-bg-warning">Por rever</span>
                                @endif
                            </div>
                            <p class="text-secondary small mt-2 mb-2">{{ $step['description'] }}</p>
                            <p class="small fw-semibold mb-3">{{ $step['detail'] }}</p>

                            @if (!$done && !$locked)
                                <form method="POST" action="{{ route('financial.review.step') }}">
                                    @csrf
                                    <input type="hidden" name="step" value="{{ $step['id'] }}">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirm-{{ $step['id'] }}" required>
                                        <label class="form-check-label small" for="confirm-{{ $step['id'] }}">Confirmo que revi esta área e que os dados estão corretos ou que já corrigi o que era necessário.</label>
                                    </div>
                                    <button class="btn btn-outline-primary btn-sm rounded-3" type="submit">Confirmar etapa</button>
                                </form>
                            @elseif ($step['id'] === 'final' && !$done)
                                <form method="POST" action="{{ route('financial.review.step') }}">
                                    @csrf
                                    <input type="hidden" name="step" value="final">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirm-final" required>
                                        <label class="form-check-label small" for="confirm-final">Confirmo que revi os dados e compreendo que esta confirmação liberta o acesso à nova versão.</label>
                                    </div>
                                    <button class="btn btn-primary rounded-3" type="submit">Concluir revisão e continuar</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <p class="text-secondary small mt-4 mb-0">Podes terminar a sessão e regressar mais tarde. O progresso fica guardado. Se encontrares dados incorretos, corrige-os nas áreas correspondentes antes de confirmar a etapa.</p>
</div>
@endsection
