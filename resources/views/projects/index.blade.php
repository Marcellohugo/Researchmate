<x-layouts::app title="Proyek riset">
    <div class="space-y-6">
        <div class="flex flex-wrap justify-between gap-4"><div><p class="rm-eyebrow text-accent-content">Ruang penelitian</p><h1 class="mt-2 text-2xl font-bold">Proyek riset</h1><p class="mt-2 text-sm text-muted">Kelola pertanyaan, sumber, dan hasil penelitian Anda.</p></div><a href="{{ route('projects.create') }}" class="rm-primary-link self-start" wire:navigate><flux:icon.plus class="size-4" /> Proyek baru</a></div>
        <x-research.notice /> @if(($section ?? 'show') !== 'show')<p class="text-sm text-muted">Pilih proyek untuk membuka bagian penelitian yang Anda pilih.</p>@endif
        <form method="GET" class="flex gap-2"><input name="search" aria-label="Cari proyek" placeholder="Cari judul atau pertanyaan riset…" value="{{ request('search') }}" class="rm-input max-w-lg"><button class="rm-secondary-link">Cari</button></form>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($projects as $project)
                <article class="rm-panel flex flex-col p-5"><div class="flex items-center justify-between"><span class="rm-icon-box"><flux:icon.folder class="size-5" /></span><x-research.status>{{ $project->research_mode === 'offline' ? ('Dokumen lokal') : 'Online' }}</x-research.status></div><h2 class="mt-4 text-base font-semibold"><a href="{{ route('projects.'.($section ?? 'show'), $project) }}" class="hover:text-accent-content" wire:navigate>{{ $project->title }}</a></h2><p class="mt-2 line-clamp-3 text-sm leading-6 text-muted">{{ $project->research_question }}</p><div class="mt-auto flex items-center justify-between gap-2 border-t border-line pt-4 text-xs text-muted"><span>{{ $project->updated_at->diffForHumans() }}</span><a class="font-semibold text-accent-content" href="{{ route('projects.'.($section ?? 'show'), $project) }}" wire:navigate>Buka proyek →</a></div></article>
            @empty
                <div class="rm-panel col-span-full p-12 text-center"><flux:icon.folder-open class="mx-auto size-10 text-muted" /><h2 class="mt-4 font-semibold">Belum ada proyek yang sesuai</h2><p class="mt-2 text-sm text-muted">Mulai dengan pertanyaan riset, lalu tambahkan dokumen PDF.</p><a href="{{ route('projects.create') }}" class="rm-primary-link mt-5" wire:navigate>Buat proyek pertama</a></div>
            @endforelse
        </div>
        {{ $projects->links() }}
    </div>
</x-layouts::app>
