@props(['title', 'subtitle' => null])

<section {{ $attributes->class('rm-panel overflow-hidden') }}>
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
        <div>
            <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-1 text-xs leading-relaxed text-muted">{{ $subtitle }}</p>
            @endif
        </div>
        {{ $action ?? '' }}
    </div>
    {{ $slot }}
</section>