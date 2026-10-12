<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas antialiased">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-accent focus:p-3 focus:text-white">Lewati ke konten utama</a>

        <flux:sidebar sticky collapsible="mobile" class="rm-sidebar">
            <flux:sidebar.header class="px-1">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" tooltip="Tutup navigasi" />
            </flux:sidebar.header>

            <x-research.navigation />
            <flux:spacer />

            <div class="mt-5 rounded-xl border border-line bg-canvas p-3.5">
                <div class="flex items-center gap-2 text-xs font-semibold text-ink">
                    <flux:icon.computer-desktop variant="outline" class="size-4 text-accent-content" />
                    Riset berbasis sumber
                </div>
                <p class="mt-2 text-[11px] leading-relaxed text-muted">PDF tersimpan lokal dan bukti yang dapat ditelusuri.</p>
                <div class="mt-3 flex items-center justify-between border-t border-line pt-3 text-[10px] text-muted">
                    <span>Inferensi AI</span>
                    <span class="font-semibold text-accent-content">{{ 'Senopati online' }}</span>
                </div>
            </div>
        </flux:sidebar>

        <flux:header class="rm-topbar">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" aria-label="Buka navigasi" />

            <nav aria-label="Lokasi halaman" class="flex min-w-0 items-center gap-2 text-xs text-muted">
                <span class="hidden sm:inline">Ruang kerja</span>
                <flux:icon.chevron-right variant="micro" class="hidden size-3 sm:block" />
                <span class="truncate font-medium text-ink">{{ __($title ?? 'Dasbor') }}</span>
            </nav>

            <flux:spacer />

            <x-research.status class="hidden sm:inline-flex">
                <flux:icon.server-stack variant="outline" class="size-3.5" />
                Lingkungan lokal
            </x-research.status>

            <span class="h-6 border-l border-line" aria-hidden="true"></span>
            <x-desktop-user-menu />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group><flux:toast /></flux:toast.group>
        @endpersist
        @fluxScripts
    </body>
</html>
