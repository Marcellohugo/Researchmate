<div wire:poll.5s class="space-y-6">
    <x-research.panel title="Aktivitas enam agen" subtitle="Pembaruan otomatis setiap lima detik. Proses AI berjalan melalui antrean lokal.">
        @if($run)
            <p class="px-5 pt-5 text-sm text-muted">Alur #{{ $run->id }} · {{ __('workflow.'.$run->status) }} · Percobaan ulang: {{ $run->retry_count }} · Revisi otomatis: {{ $run->workflow_state['revision_count'] ?? 0 }}/2</p>
        @endif
        <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($agents as $name => $label)
                @php($last = $steps->where('agent_name', $name)->filter(fn($step) => !str_starts_with($step->step_name, 'tool:') && !str_starts_with($step->step_name, 'analysis:paper:'))->last())
                <article class="rounded-xl border border-line p-4">
                    <h3 class="font-semibold">{{ $label }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ ['completed' => 'Selesai', 'running' => 'Berjalan', 'pending' => 'Menunggu', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan'][$last?->status ?? 'pending'] ?? $last?->status }}</p>
                    @php($duration = $last?->status === 'running' && $last->started_at ? $last->started_at->diffInMilliseconds(now()) : $last?->duration_ms)
                    <p class="mt-2 text-xs text-muted">Mulai: {{ $last?->started_at?->format('d M Y H:i:s') ?? '—' }}<br>Durasi: {{ $duration !== null ? number_format($duration / 1000, 1).' detik'.($last?->status === 'running' ? ' · masih berjalan' : '') : '—' }}</p>
                </article>
            @endforeach
        </div>
    </x-research.panel>
    <x-research.panel title="Log eksekusi" subtitle="Tahap, pemanggilan alat, hasil tersimpan, dan kesalahan.">
        <div class="space-y-3 p-5">
            @forelse($steps as $step)
                <details class="rounded-xl border border-line p-4" wire:key="step-{{ $step->id }}">
                    <summary class="cursor-pointer text-sm font-medium">{{ $agents[$step->agent_name] ?? $step->agent_name }} · {{ $step->step_name }} · {{ ['completed' => 'Selesai', 'running' => 'Berjalan', 'failed' => 'Gagal', 'pending' => 'Menunggu', 'cancelled' => 'Dibatalkan'][$step->status] ?? $step->status }}</summary>
                    @if($step->error_message)<p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $step->error_message }}</p>@endif
                    @if($step->output_payload)<pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs">{{ json_encode($step->output_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                </details>
            @empty
                <p class="text-sm text-muted">Belum ada eksekusi agen. Mulai alur dari workspace proyek.</p>
            @endforelse
        </div>
    </x-research.panel>
    @if(!empty($run?->workflow_state['failures']))
        <x-research.panel title="Riwayat kegagalan dan percobaan ulang" subtitle="Maksimal 30 kegagalan terakhir tetap disimpan saat tahap dicoba ulang.">
            <div class="space-y-3 p-5">
                @foreach($run->workflow_state['failures'] as $failure)
                    <div class="rounded-xl border border-line p-4 text-sm">
                        <p class="font-medium">{{ __('workflow.'.$failure['stage']) }} · Percobaan {{ $failure['attempt'] }} · {{ $failure['at'] }}</p>
                        <p class="mt-2 text-red-600 dark:text-red-400">{{ $failure['error'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-research.panel>
    @endif
</div>
