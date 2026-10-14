<x-layouts::app title="Laporan riset"><div class="space-y-6"><x-research.project-nav :project="$project" />
    <x-research.notice />
    <x-research.panel title="Laporan penelitian" subtitle="Simpan rencana, perbandingan artikel, temuan, kandidat celah, bukti, dan daftar pustaka.">
        <div class="space-y-5 p-5">
            <form action="{{ route('projects.reports.export',$project) }}" method="POST" class="flex flex-wrap items-end gap-4">
                @csrf
                <div><label for="report-format" class="rm-label">Format laporan</label><select id="report-format" name="format" class="rm-input">
                    @foreach(['docx'=>'Word (.docx)','pdf'=>'PDF','markdown'=>'Markdown (.md)'] as $value=>$label)<option value="{{ $value }}" @selected(old('format','docx')===$value)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="citation-style" class="rm-label">Gaya daftar pustaka</label><select id="citation-style" name="citation_style" class="rm-input">
                    <option value="apa" @selected(old('citation_style','apa')==='apa')>APA</option><option value="ieee" @selected(old('citation_style')==='ieee')>IEEE</option>
                </select></div>
                <button class="rm-primary-link"><flux:icon.arrow-down-tray class="size-4" /> Simpan laporan</button>
            </form>
            <p class="text-xs leading-5 text-muted">Laporan mengikuti run terbaru. Status persetujuan dan bukti tercantum; hasil yang belum disetujui tetap ditandai sebagai draf. Lengkapi metadata artikel sebelum menyiapkan daftar pustaka.</p>
            <div class="divide-y divide-line">
                @forelse($artifacts as $artifact)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div><h2 class="text-sm font-semibold">{{ $artifact->title ?: 'Laporan penelitian' }}</h2><p class="mt-1 text-xs text-muted">{{ $artifact->created_at->format('d M Y H:i') }} · {{ match($artifact->artifact_type) { 'word_report'=>'Word','pdf_report'=>'PDF',default=>'Markdown' } }} @if($artifact->run_id)· Run #{{ $artifact->run_id }}@endif</p></div>
                        <a href="{{ route('projects.reports.download',[$project,$artifact]) }}" class="rm-secondary-link text-xs">Unduh laporan</a>
                    </div>
                @empty<p class="py-5 text-sm text-muted">Belum ada laporan tersimpan.</p>@endforelse
            </div>
        </div>
    </x-research.panel>
    @if($latestRun && data_get($latestRun->workflow_state, 'verifying.feedback'))
        <x-research.panel title="Catatan pemeriksaan akhir">
            <div class="space-y-3 p-5 text-sm leading-7">
                <p class="whitespace-pre-wrap">{{ data_get($latestRun->workflow_state, 'verifying.feedback') }}</p>
                @if(data_get($latestRun->workflow_state, 'revision_limit_reached'))
                    <p class="font-semibold text-danger">Batas revisi otomatis tercapai. Temuan yang masih bermasalah memerlukan tinjauan manusia sebelum digunakan.</p>
                @endif
            </div>
        </x-research.panel>
    @endif
    <x-research.panel title="Verifikasi klaim" subtitle="Pemeriksaan kutipan tidak menggantikan penilaian ilmiah. Metadata offline tetap belum diverifikasi secara eksternal.">
        <div class="space-y-5 p-5">
            @forelse($claims as $claim)
                <article class="rounded-xl border border-line p-4">
                    <x-research.status :tone="$claim->verification_status === 'verified' ? 'green' : 'amber'">{{ __('evidence.'.$claim->verification_status) }}</x-research.status>
                    <p class="mt-3 text-sm leading-7">{{ $claim->claim_text }}</p>
                    @foreach($claim->evidence as $source)
                        <blockquote class="mt-3 border-l-2 border-accent pl-3 text-xs leading-6 text-muted">{{ $source->evidence_text }}</blockquote>
                        @if($source->chunk_id)<a href="{{ route('projects.evidence',[$project,$source->chunk_id]) }}" class="mt-2 block text-xs text-accent-content" wire:navigate>{{ $source->paper->title }} · sumber #{{ $source->chunk_id }}</a>@else<p class="mt-2 text-xs text-muted">Bukti dari abstrak: {{ $source->paper->title }}</p>@endif
                    @endforeach
                </article>
            @empty
                <p class="text-sm text-muted">Klaim dan hasil verifikasi muncul setelah tahap sintesis.</p>
            @endforelse
        </div>
    </x-research.panel>
</div></x-layouts::app>
