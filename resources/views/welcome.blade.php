<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Selamat datang'])
    </head>
    <body class="min-h-screen bg-canvas antialiased">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-line py-6">
                <x-app-logo href="{{ route('home') }}" wire:navigate />
                <nav aria-label="Akses akun" class="flex items-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rm-primary-link" wire:navigate>Buka dasbor <flux:icon.arrow-right variant="micro" class="size-4" /></a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-semibold text-ink hover:text-accent-content" wire:navigate>Masuk</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rm-primary-link" wire:navigate>Daftar</a>
                        @endif
                    @endauth
                </nav>
            </header>

            <main id="main-content" class="py-14 lg:py-24">
                <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <x-research.status tone="blue"><flux:icon.academic-cap variant="outline" class="size-3.5" /> RUANG KERJA PENELITIAN</x-research.status>
                        <h1 class="mt-6 text-4xl leading-[1.13] font-bold tracking-tight text-ink sm:text-5xl">Pertanyaan yang baik.<br><span class="text-accent-content">Riset yang terarah.</span></h1>
                        <p class="mt-6 max-w-md text-base leading-7 text-muted">ResearchMate AI dirancang untuk menghubungkan literatur, analisis, dan bukti dalam satu ruang penelitian dengan AI melalui gateway Senopati.</p>
                        <p class="mt-4 max-w-md text-sm leading-6 text-muted">Unggah PDF, telusuri literatur, dan jalankan enam agen AI untuk menyiapkan analisis yang dapat Anda tinjau dari sumbernya.</p>

                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            @auth
                                <a href="{{ route('dashboard') }}" class="rm-primary-link min-h-11! px-5!" wire:navigate>Masuk ke ruang kerja <flux:icon.arrow-right variant="micro" class="size-4" /></a>
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="rm-primary-link min-h-11! px-5!" wire:navigate>Buat akun penelitian <flux:icon.arrow-right variant="micro" class="size-4" /></a>
                                @else
                                    <a href="{{ route('login') }}" class="rm-primary-link min-h-11! px-5!" wire:navigate>Masuk ke ruang kerja</a>
                                @endif
                            @endauth
                        </div>
                        <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted">
                            <span class="inline-flex items-center gap-1.5"><flux:icon.computer-desktop variant="outline" class="size-4 text-accent-content" /> Inferensi melalui Senopati</span>
                            <span class="inline-flex items-center gap-1.5"><flux:icon.shield-check variant="outline" class="size-4 text-accent-content" /> Akses akun pribadi</span>
                        </div>
                    </div>

                    <section class="rm-landing-grid relative overflow-hidden rounded-3xl border border-line p-6 sm:p-8" aria-label="Tahapan pengembangan ruang riset">
                        <div class="absolute inset-0 bg-gradient-to-br from-blue-50/60 to-transparent dark:from-blue-950/20" aria-hidden="true"></div>
                        <div class="relative">
                            <div class="mb-6 flex items-center justify-between gap-3">
                                <p class="rm-eyebrow text-muted">Perjalanan ResearchMate</p>
                                <x-research.status tone="blue">{{ 'Senopati online' }}</x-research.status>
                            </div>

                            <div class="rm-panel p-5 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-white"><flux:icon.user-circle variant="outline" class="size-6" /></span>
                                    <div>
                                        <h2 class="text-sm font-semibold text-ink">Ruang kerja pribadi</h2>
                                        <p class="mt-1 text-xs text-muted">Akun, autentikasi, dan preferensi</p>
                                        <x-research.status tone="green" class="mt-3">Tersedia sekarang</x-research.status>
                                    </div>
                                </div>
                            </div>
                            <div class="ml-10 h-6 border-l-2 border-dashed border-blue-200 dark:border-blue-900" aria-hidden="true"></div>
                            <div class="rm-panel p-5">
                                <div class="flex items-start gap-3">
                                    <span class="rm-icon-box"><flux:icon.folder-open variant="outline" class="size-5" /></span>
                                    <div>
                                        <h2 class="text-sm font-semibold text-ink">Proyek &amp; sumber penelitian</h2>
                                        <p class="mt-1 text-xs leading-5 text-muted">Pertanyaan riset dan dokumen ilmiah</p>
                                        <p class="mt-3 text-[11px] font-medium text-muted">Proyek privat · PDF dan pencarian semantik</p>
                                    </div>
                                </div>
                            </div>
                            <div class="ml-10 h-6 border-l-2 border-dashed border-blue-200 dark:border-blue-900" aria-hidden="true"></div>
                            <div class="rm-panel p-5">
                                <div class="flex items-start gap-3">
                                    <span class="rm-icon-box"><flux:icon.cpu-chip variant="outline" class="size-5" /></span>
                                    <div>
                                        <h2 class="text-sm font-semibold text-ink">Analisis multiagen berbasis bukti</h2>
                                        <p class="mt-1 text-xs leading-5 text-muted">Sintesis, kandidat celah, dan verifikasi</p>
                                        <p class="mt-3 text-[11px] font-medium text-muted">Enam agen · Persetujuan manusia</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <section class="mt-16 grid gap-6 border-t border-line pt-8 sm:grid-cols-3" aria-label="Fondasi yang tersedia">
                    <div>
                        <flux:icon.finger-print variant="outline" class="mb-3 size-5 text-accent-content" />
                        <h2 class="text-sm font-semibold text-ink">Autentikasi yang terhubung</h2>
                        <p class="mt-2 text-xs leading-6 text-muted">Registrasi, masuk, keluar, dan pengelolaan keamanan akun melalui Laravel.</p>
                    </div>
                    <div>
                        <flux:icon.squares-2x2 variant="outline" class="mb-3 size-5 text-accent-content" />
                        <h2 class="text-sm font-semibold text-ink">Ruang kerja yang jelas</h2>
                        <p class="mt-2 text-xs leading-6 text-muted">Dasbor menampilkan kesiapan aplikasi dan ketersediaan setiap fitur secara terbuka.</p>
                    </div>
                    <div>
                        <flux:icon.sun variant="outline" class="mb-3 size-5 text-accent-content" />
                        <h2 class="text-sm font-semibold text-ink">Nyaman di perangkat Anda</h2>
                        <p class="mt-2 text-xs leading-6 text-muted">Navigasi responsif, tema terang atau gelap, dan tipografi tanpa unduhan font eksternal.</p>
                    </div>
                </section>
            </main>

            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line py-6 text-[11px] text-muted">
                <span>ResearchMate AI · Asisten riset multiagen</span>
                <span>Untuk mahasiswa, dosen, dan peneliti.</span>
            </footer>
        </div>
        @fluxScripts
    </body>
</html>
