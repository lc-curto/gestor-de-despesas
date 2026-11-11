<nav class="app-navigation" aria-label="Navegação da aplicação">
    <div class="app-navigation-group">
        <p class="app-navigation-label">Principal</p>
        <a class="app-navigation-link {{ request()->routeIs('dashboard*') ? 'active' : '' }}"
            href="{{ route('dashboard') }}" title="Dashboard" aria-label="Dashboard"
            @if(request()->routeIs('dashboard*')) aria-current="page" @endif>
            <i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Dashboard</span>
        </a>
    </div>

    <div class="app-navigation-group">
        <p class="app-navigation-label">Movimentos</p>
        <a class="app-navigation-link {{ request()->routeIs('despesas.*') ? 'active' : '' }}"
            href="{{ route('despesas.index') }}" title="Despesas" aria-label="Despesas"
            @if(request()->routeIs('despesas.*')) aria-current="page" @endif>
            <i data-lucide="arrow-down-circle" aria-hidden="true"></i><span>Despesas</span>
        </a>
        <a class="app-navigation-link {{ request()->routeIs('receitas.*') ? 'active' : '' }}"
            href="{{ route('receitas.index') }}" title="Receitas" aria-label="Receitas"
            @if(request()->routeIs('receitas.*')) aria-current="page" @endif>
            <i data-lucide="arrow-up-circle" aria-hidden="true"></i><span>Receitas</span>
        </a>
    </div>

    <div class="app-navigation-group">
        <p class="app-navigation-label">Organização</p>
        <a class="app-navigation-link {{ request()->routeIs('categorias.*') ? 'active' : '' }}"
            href="{{ route('categorias.index') }}" title="Categorias" aria-label="Categorias"
            @if(request()->routeIs('categorias.*')) aria-current="page" @endif>
            <i data-lucide="tags" aria-hidden="true"></i><span>Categorias</span>
        </a>
    </div>
</nav>