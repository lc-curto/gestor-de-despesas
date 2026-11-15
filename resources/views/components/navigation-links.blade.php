<nav class="app-navigation" aria-label="Navegação da aplicação">
    <div class="app-navigation-group">
        <p class="app-navigation-label">Visão geral</p>
        <a class="app-navigation-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
            href="{{ route('dashboard') }}" title="Início" aria-label="Início"
            @if(request()->routeIs('dashboard')) aria-current="page" @endif>
            <i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Início</span>
        </a>
        <a class="app-navigation-link {{ request()->routeIs('dashboard.movimentos') ? 'active' : '' }}"
            href="{{ route('dashboard.movimentos') }}" title="Movimentos" aria-label="Movimentos"
            @if(request()->routeIs('dashboard.movimentos')) aria-current="page" @endif>
            <i data-lucide="arrow-left-right" aria-hidden="true"></i><span>Movimentos</span>
        </a>
    </div>

    <div class="app-navigation-group">
        <p class="app-navigation-label">Finanças</p>
        <a class="app-navigation-link {{ request()->routeIs('financial.accounts.*') ? 'active' : '' }}"
            href="{{ route('financial.accounts.page') }}" title="Contas" aria-label="Contas"
            @if(request()->routeIs('financial.accounts.*')) aria-current="page" @endif>
            <i data-lucide="wallet-cards" aria-hidden="true"></i><span>Contas</span>
        </a>
        <a class="app-navigation-link {{ request()->routeIs('financial.commitments.*') ? 'active' : '' }}"
            href="{{ route('financial.commitments.page') }}" title="Cartões e compromissos" aria-label="Cartões e compromissos"
            @if(request()->routeIs('financial.commitments.*')) aria-current="page" @endif>
            <i data-lucide="credit-card" aria-hidden="true"></i><span>Cartões e compromissos</span>
        </a>
    </div>

    <div class="app-navigation-group">
        <p class="app-navigation-label">Configuração</p>
        <a class="app-navigation-link {{ request()->routeIs('categorias.*') ? 'active' : '' }}"
            href="{{ route('categorias.index') }}" title="Categorias" aria-label="Categorias"
            @if(request()->routeIs('categorias.*')) aria-current="page" @endif>
            <i data-lucide="tags" aria-hidden="true"></i><span>Categorias</span>
        </a>
    </div>
</nav>

