<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="min-w-0 p-5! lg:p-8!" id="main-content" tabindex="-1">
        <div class="mx-auto w-full max-w-[1440px]">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>