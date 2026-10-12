@php($activeProject = request()->route('project'))
<flux:sidebar.nav aria-label="Navigasi riset" class="gap-1">
    <p class="rm-eyebrow px-3 pb-2 pt-5 text-muted">Ruang kerja</p>
    <a href="{{ route('dashboard') }}" class="rm-nav-item" @if(request()->routeIs('dashboard')) aria-current="page" @endif wire:navigate><flux:icon.home variant="outline" /><span>Dasbor</span></a>
    <a href="{{ route('projects.index') }}" class="rm-nav-item" @if(request()->routeIs('projects.index','projects.create','projects.edit','projects.show')) aria-current="page" @endif wire:navigate><flux:icon.folder variant="outline" /><span>Proyek riset</span></a>
    @foreach ([['literature.index','Penelusuran literatur','magnifying-glass'], ['documents.index','Pustaka artikel & PDF','book-open'], ['matrix','Matriks literatur','table-cells'], ['gaps','Kandidat celah riset','light-bulb'], ['chat.index','Percakapan riset AI','chat-bubble-left-right'], ['agents','Monitor agen','cpu-chip'], ['reports','Laporan riset','chart-bar-square']] as [$route,$label,$icon])
        <a href="{{ $activeProject instanceof \App\Models\ResearchProject ? route('projects.'.$route,$activeProject) : route('projects.index',['section'=>$route]) }}" class="rm-nav-item" @if(request()->routeIs('projects.'.$route)) aria-current="page" @endif wire:navigate><flux:icon :icon="$icon" variant="outline" /><span>{{ $label }}</span></a>
    @endforeach
    <p class="rm-eyebrow px-3 pb-2 pt-5 text-muted">Preferensi</p>
    <a href="{{ route('ai.settings') }}" class="rm-nav-item" @if(request()->routeIs('ai.*')) aria-current="page" @endif wire:navigate><flux:icon.server-stack variant="outline" /><span>Pengaturan AI</span></a>
    <a href="{{ route('profile.edit') }}" class="rm-nav-item" @if(request()->routeIs('profile.edit','security.edit','appearance.edit')) aria-current="page" @endif wire:navigate><flux:icon.cog-6-tooth variant="outline" /><span>Pengaturan akun</span></a>
</flux:sidebar.nav>
