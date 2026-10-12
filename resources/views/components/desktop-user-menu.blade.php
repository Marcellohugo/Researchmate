<flux:dropdown position="bottom" align="end" {{ $attributes }}>
    <flux:button variant="ghost" class="gap-2.5! px-1.5!" aria-label="Buka menu akun" data-test="sidebar-menu-button">
        <span class="flex size-8 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ auth()->user()->initials() }}</span>
        <span class="hidden max-w-32 truncate text-sm font-medium text-ink sm:block">{{ auth()->user()->name }}</span>
        <flux:icon.chevron-down variant="micro" class="size-4 text-muted" />
    </flux:button>

    <flux:menu>
        <div class="px-2 py-2">
            <flux:heading class="max-w-56 truncate">{{ auth()->user()->name }}</flux:heading>
            <flux:text class="max-w-56 truncate text-xs!">{{ auth()->user()->email }}</flux:text>
        </div>
        <flux:menu.separator />
        <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>Profil saya</flux:menu.item>
        <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>Keamanan akun</flux:menu.item>
        <flux:menu.item :href="route('appearance.edit')" icon="sun" wire:navigate>Tampilan</flux:menu.item>
        <flux:menu.separator />
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">Keluar</flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>