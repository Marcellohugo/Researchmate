@props(['project'])
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><a href="{{ route('projects.index') }}" class="text-xs text-accent-content" wire:navigate>← Semua proyek</a><h1 class="mt-2 break-words text-2xl font-bold text-ink">{{ $project->title }}</h1></div>
        <x-research.status :tone="$project->research_mode === 'offline' ? 'green' : 'blue'">{{ $project->research_mode === 'offline' ? ('Dokumen lokal · AI online') : 'Penelusuran online' }}</x-research.status>
    </div>
    <nav aria-label="Bagian proyek" class="flex gap-1 overflow-x-auto border-b border-line pb-2">
        @foreach (['show' => 'Ringkasan', 'literature.index' => 'Literatur', 'documents.index' => 'PDF', 'matrix' => 'Matriks', 'gaps' => 'Celah riset', 'chat.index' => 'Percakapan', 'agents' => 'Agen', 'reports' => 'Laporan'] as $route => $label)
            <a href="{{ route('projects.'.$route, $project) }}" class="shrink-0 rounded-lg px-3 py-2 text-xs font-medium {{ request()->routeIs('projects.'.$route) ? 'bg-blue-50 text-accent-content dark:bg-blue-950' : 'text-muted hover:bg-surface-soft' }}" @if(request()->routeIs('projects.'.$route)) aria-current="page" @endif wire:navigate>{{ $label }}</a>
        @endforeach
    </nav>
    <x-research.notice />
</div>
