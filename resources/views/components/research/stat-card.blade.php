@props(['label', 'icon', 'phase'])

<section class="rm-panel p-5">
    <div class="flex items-center justify-between gap-3">
        <p class="text-xs font-medium text-muted">{{ $label }}</p>
        <span class="rm-icon-box size-9! rounded-lg!"><flux:icon :icon="$icon" variant="outline" class="size-4.5" /></span>
    </div>
    <p class="mt-3 text-3xl font-semibold tracking-tight text-ink" aria-label="Data belum tersedia">—</p>
    <p class="mt-2 text-[11px] text-muted">Belum tersedia <span class="mx-1 text-line">·</span> Fase {{ $phase }}</p>
</section>