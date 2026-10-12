<x-layouts::app title="Pengaturan AI">
    <div class="mx-auto max-w-3xl space-y-6">
        <div><h1 class="text-2xl font-bold">AI dan koneksi</h1><p class="mt-2 text-sm text-muted">Model dan batas pemrosesan berasal dari konfigurasi lingkungan aplikasi.</p></div>
        <x-research.notice />
        <x-research.panel title="Gateway Senopati">
            <div class="space-y-5 p-6">
                <dl class="grid gap-5 text-sm sm:grid-cols-2">
                    @foreach(['Gateway' => 'Senopati ITS', 'Alamat API' => 'https://senopati.its.ac.id/llm-gateway/v1', 'Model percakapan' => config('ai.chat_model'), 'Model embedding' => config('ai.embed_model'), 'Batas waktu' => config('ai.senopati_timeout').' detik', 'Target maksimum' => config('ai.max_papers').' artikel', 'Batas PDF' => config('ai.max_pdf_mb').' MB'] as $label => $value)
                        <div><dt class="text-xs text-muted">{{ $label }}</dt><dd class="mt-1 break-all font-mono">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <form method="POST" action="{{ route('ai.check') }}">@csrf<button class="rm-primary-link">Periksa koneksi dan model</button></form>
                @if($health)
                    <div role="status" class="space-y-3 rounded-xl border border-line bg-canvas p-4 text-sm leading-6">
                        <p class="font-semibold">{{ data_get($health, 'message') }}</p>
                        @if(data_get($health, 'connected'))
                            <p>Model percakapan: {{ data_get($health, 'chat_available') ? 'Tersedia' : 'Belum ditemukan' }}.</p>
                            <p>Model embedding: {{ data_get($health, 'embedding_available') ? 'Tersedia' : 'Belum ditemukan' }}.</p>
                            <p class="break-all text-xs text-muted">Model tersedia di gateway: {{ implode(', ', data_get($health, 'models', [])) ?: 'Belum ada model' }}.</p>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-muted">Belum diperiksa. Pastikan token tersedia dan periksa koneksi gateway.</p>
                @endif
                <p class="text-xs leading-6 text-muted">Untuk mengubah model, perbarui konfigurasi lingkungan dan bersihkan cache konfigurasi. Setelah mengganti model embedding, indeks ulang dokumen dengan perintah yang tersedia dalam panduan pemasangan. Embedding lama tidak dicampur dengan model baru.</p>
                <p class="rounded-xl border border-line bg-canvas p-4 text-sm leading-6">Seluruh inferensi chat, agen, dan embedding melalui Senopati ITS dan memerlukan internet. Pertanyaan serta cuplikan teks PDF dikirim ke gateway; PDF dan vector hasilnya disimpan lokal. Mode dokumen lokal menonaktifkan OpenAlex/Crossref.</p>
            </div>
        </x-research.panel>
    </div>
</x-layouts::app>
