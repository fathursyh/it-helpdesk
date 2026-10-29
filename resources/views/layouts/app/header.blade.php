<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('tickets.index') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="ticket" :href="route('tickets.index')" :current="request()->routeIs('tickets.index') || request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Tickets') }}
                </flux:navbar.item>
                <flux:navbar.item icon="plus-circle" :href="route('tickets.create')" :current="request()->routeIs('tickets.create')" wire:navigate>
                    {{ __('Submit Issue') }}
                </flux:navbar.item>
                @if (auth()->user()?->isIT())
                    <flux:navbar.item icon="users" :href="route('it-accounts.index')" :current="request()->routeIs('it-accounts.*')" wire:navigate>
                        {{ __('IT Accounts') }}
                    </flux:navbar.item>
                @endif
            </flux:navbar>

            <flux:spacer />

            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('tickets.index') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Helpdesk')" class="grid">
                    <flux:sidebar.item icon="ticket" :href="route('tickets.index')" :current="request()->routeIs('tickets.*')" wire:navigate>
                        {{ __('Tickets') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="plus-circle" :href="route('tickets.create')" :current="request()->routeIs('tickets.create')" wire:navigate>
                        {{ __('Submit Issue') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if (auth()->user()?->isIT())
                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        <flux:sidebar.item icon="users" :href="route('it-accounts.index')" :current="request()->routeIs('it-accounts.*')" wire:navigate>
                            {{ __('IT Accounts') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
