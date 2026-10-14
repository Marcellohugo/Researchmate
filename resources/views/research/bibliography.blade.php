<x-layouts::app title="Daftar pustaka">
    <div class="space-y-6">
        <x-research.project-nav :project="$project" />
        <x-research.panel title="Daftar pustaka" subtitle="Sitasi teks untuk koleksi proyek ini. Lengkapi metadata dan periksa nama penulis pada publikasi asli sebelum digunakan.">
            <div class="space-y-5 p-5 sm:p-6">
                <x-research.notice />
                <form method="GET" action="{{ route('projects.literature.references', $project) }}" class="flex flex-wrap items-center gap-3">
                    <label for="citation-style" class="text-sm font-medium">Gaya sitasi</label><select id="citation-style" name="style" class="rm-input w-auto"><option value="apa" @selected($style === 'apa')>APA 7 (teks)</option><option value="ieee" @selected($style === 'ieee')>IEEE (teks)</option></select><button class="rm-secondary-link">Tampilkan</button>
                    <a class="rm-primary-link" href="{{ route('projects.literature.references', [$project, 'style' => $style, 'download' => 1]) }}">Unduh teks</a>
                </form>
                <p class="text-xs text-muted">Format teks tidak menyertakan huruf miring atau hanging indent. Nomor IEEE di sini mengikuti daftar ini; sesuaikan dengan urutan sitasi dalam naskah Anda. Artikel tanpa data lengkap memiliki penanda untuk dilengkapi.</p>
                <div class="divide-y divide-line">
                    @forelse($citations as $index => $citation)
                        <div class="space-y-3 py-4"><p class="break-words text-sm leading-7">@if($style === 'ieee')[{{ $index + 1 }}] @endif{{ $citation }}</p><a class="text-xs text-accent-content hover:underline" href="{{ route('projects.literature.edit', [$project, $papers[$index]]) }}" wire:navigate>Ubah metadata artikel</a></div>
                    @empty
                        <p class="py-8 text-center text-sm text-muted">Tambahkan artikel atau PDF untuk membuat daftar pustaka.</p>
                    @endforelse
                </div>
            </div>
        </x-research.panel>
    </div>
</x-layouts::app>
