<x-layouts::app title="Ubah metadata artikel">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-research.project-nav :project="$project" />
        <x-research.panel title="Ubah metadata artikel" subtitle="Perubahan berlaku hanya untuk proyek ini. Data yang Anda tinjau belum dianggap terverifikasi oleh penerbit atau DOI.">
            <div class="space-y-5 p-5 sm:p-6">
                <x-research.notice />
                @if($activeRun)
                    <p role="status" class="text-sm text-muted">Alur riset masih aktif. Selesaikan atau batalkan alur sebelum menyimpan metadata.</p>
                @endif
                <form method="POST" action="{{ route('projects.literature.update', [$project, $paper]) }}" class="space-y-5">
                    @csrf @method('PUT')
                    <div><label for="paper-title" class="mb-2 block text-sm font-medium">Judul artikel</label><textarea id="paper-title" name="title" class="rm-input min-h-24 w-full" required maxlength="2000">{{ old('title', $paper->title) }}</textarea></div>
                    <div>
                        <label for="paper-authors" class="mb-2 block text-sm font-medium">Penulis — satu nama per baris</label>
                        @php($authorLines = collect($paper->authors ?? [])->map(fn($author) => is_array($author) ? (isset($author['family']) ? $author['family'].', '.($author['given'] ?? '') : ($author['name'] ?? $author['display_name'] ?? '')) : $author)->implode("\n"))
                        <textarea id="paper-authors" name="authors" class="rm-input min-h-32 w-full" maxlength="25500" aria-describedby="authors-help">{{ old('authors', $authorLines) }}</textarea>
                        <p id="authors-help" class="mt-2 text-xs text-muted">Untuk nama dengan beberapa bagian, gunakan “Nama keluarga, Nama depan” agar inisial sitasi tepat. Nama tunggal boleh ditulis apa adanya. Maksimal 100 penulis.</p>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><label for="paper-year" class="mb-2 block text-sm font-medium">Tahun terbit</label><input id="paper-year" type="number" name="publication_year" class="rm-input w-full" min="1500" max="{{ now()->year + 1 }}" value="{{ old('publication_year', $paper->publication_year) }}"></div>
                        <div><label for="paper-doi" class="mb-2 block text-sm font-medium">DOI</label><input id="paper-doi" name="doi" class="rm-input w-full" maxlength="255" placeholder="10.1234/artikel.2026" value="{{ old('doi', $paper->doi) }}"></div>
                    </div>
                    <div><label for="paper-journal" class="mb-2 block text-sm font-medium">Nama jurnal / publikasi</label><input id="paper-journal" name="journal_name" class="rm-input w-full" maxlength="255" value="{{ old('journal_name', $paper->journal_name) }}"></div>
                    <div class="grid gap-5 sm:grid-cols-3">
                        @foreach(['volume' => 'Volume', 'issue' => 'Nomor', 'pages' => 'Halaman / nomor artikel'] as $field => $label)
                            <div><label for="paper-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label><input id="paper-{{ $field }}" name="{{ $field }}" class="rm-input w-full" maxlength="{{ $field === 'pages' ? 80 : 50 }}" value="{{ old($field, $paper->getAttribute($field)) }}"></div>
                        @endforeach
                    </div>
                    <div><label for="paper-source" class="mb-2 block text-sm font-medium">URL sumber</label><input id="paper-source" name="source_url" type="url" class="rm-input w-full" maxlength="4000" placeholder="https://..." value="{{ old('source_url', $paper->source_url) }}"></div>
                    <p class="text-xs text-muted">Kosongkan data yang belum diketahui. Daftar pustaka akan menandai metadata penting yang belum tersedia. Abstrak, isi PDF, dan hasil riset lama tidak diubah oleh form ini.</p>
                    <div class="flex flex-wrap gap-3"><button type="submit" class="rm-primary-link disabled:opacity-50" @disabled($activeRun)>Simpan metadata</button><a class="rm-secondary-link" href="{{ route('projects.literature.index', $project) }}" wire:navigate>Kembali ke literatur</a></div>
                </form>
            </div>
        </x-research.panel>
    </div>
</x-layouts::app>
