<nav class="app-navigation" aria-label="Navegação da aplicação">
    <p class="app-navigation-label">Principal</p>
    <a class="app-navigation-link {{ request()->routeIs('dashboard*') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard*')) aria-current="page" @endif>
        <i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span>
    </a>
    <p class="app-navigation-label mt-4">Movimentos</p>
    <a class="app-navigation-link {{ request()->routeIs('despesas.*') ? 'active' : '' }}" href="{{ route('despesas.index') }}" @if(request()->routeIs('despesas.*')) aria-current="page" @endif>
        <i class="bi bi-arrow-down-circle" aria-hidden="true"></i><span>Despesas</span>
    </a>
    <a class="app-navigation-link {{ request()->routeIs('receitas.*') ? 'active' : '' }}" href="{{ route('receitas.index') }}" @if(request()->routeIs('receitas.*')) aria-current="page" @endif>
        <i class="bi bi-arrow-up-circle" aria-hidden="true"></i><span>Receitas</span>
    </a>
    <p class="app-navigation-label mt-4">Organização</p>
    <a class="app-navigation-link {{ request()->routeIs('categorias.*') ? 'active' : '' }}" href="{{ route('categorias.index') }}" @if(request()->routeIs('categorias.*')) aria-current="page" @endif>
        <i class="bi bi-tags" aria-hidden="true"></i><span>Categorias</span>
    </a>
</nav>
