@props(['sidebar' => false])

<a {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent text-white shadow-sm">
        <x-app-logo-icon class="size-7" />
    </span>
    <span class="min-w-0">
        <span class="block text-base font-bold tracking-tight text-ink">ResearchMate <span class="text-accent-content">AI</span></span>
        @if ($sidebar)
            <span class="mt-0.5 block text-[10px] font-medium tracking-wide text-muted">ASISTEN RISET AKADEMIK</span>
        @endif
    </span>
</a>