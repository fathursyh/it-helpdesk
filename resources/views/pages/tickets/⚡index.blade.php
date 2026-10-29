<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('IT Helpdesk - Tickets')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $priority = 'all';

    #[Url]
    public string $division = 'all';

    #[Url]
    public string $category = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedDivision(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'priority', 'division', 'category']);
        $this->resetPage();
    }

    /**
     * Paginated tickets based on current filters and user permissions.
     */
    #[Computed]
    public function tickets(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = Auth::user();

        return Ticket::query()
            ->forUser($user)
            ->with(['user', 'assignedTo'])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $subQuery): void {
                    $subQuery
                        ->where('title', 'like', "%{$this->search}%")
                        ->orWhere('ticket_number', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhereHas('user', function (Builder $userQuery): void {
                            $userQuery->where('name', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->status !== 'all', fn(Builder $q) => $q->where('status', $this->status))
            ->when($this->priority !== 'all', fn(Builder $q) => $q->where('priority', $this->priority))
            ->when($this->division !== 'all', fn(Builder $q) => $q->where('division', $this->division))
            ->when($this->category !== 'all', fn(Builder $q) => $q->where('category', $this->category))
            ->orderByRaw(
                "CASE
                WHEN priority = 'urgent' AND status IN ('open', 'in_progress') THEN 1
                WHEN priority = 'high' AND status IN ('open', 'in_progress') THEN 2
                WHEN priority = 'medium' AND status IN ('open', 'in_progress') THEN 3
                WHEN priority = 'low' AND status IN ('open', 'in_progress') THEN 4
                ELSE 5 END",
            )
            ->latest('created_at')
            ->paginate(10);
    }

    /**
     * Metrics for IT staff or employees.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isIT()) {
            return [
                'open' => Ticket::where('status', TicketStatus::Open)->count(),
                'in_progress' => Ticket::where('status', TicketStatus::InProgress)->count(),
                'unassigned' => Ticket::whereNull('assigned_to_id')
                    ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                    ->count(),
                'resolved' => Ticket::where('status', TicketStatus::Resolved)->count(),
            ];
        }

        return [
            'open' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Open)->count(),
            'in_progress' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::InProgress)->count(),
            'resolved' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Resolved)->count(),
            'closed' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Closed)->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6 w-full">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('IT Helpdesk Tickets') }}</flux:heading>
            <flux:subheading>
                @if (auth()->user()->isIT())
                    {{ __('Manage, prioritize, and resolve IT issues across all company divisions.') }}
                @else
                    {{ __('Track your submitted IT requests and get updates from the IT team.') }}
                @endif
            </flux:subheading>
        </div>

        <div>
            <flux:button href="{{ route('tickets.create') }}" variant="primary" icon="plus" wire:navigate>
                {{ __('Submit New Issue') }}
            </flux:button>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @if (auth()->user()->isIT())
            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                    <flux:icon name="inbox-stack" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('Open Tickets') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['open'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                    <flux:icon name="arrow-path" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('In Progress') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['in_progress'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400">
                    <flux:icon name="user-minus" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('Unassigned') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['unassigned'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                    <flux:icon name="check-circle" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('Resolved') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['resolved'] }}</flux:heading>
                </div>
            </flux:card>
        @else
            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                    <flux:icon name="inbox-stack" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('My Open Issues') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['open'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                    <flux:icon name="arrow-path" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('In Progress') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['in_progress'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                    <flux:icon name="check-circle" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('Resolved') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['resolved'] }}</flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4 p-5">
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    <flux:icon name="archive-box" class="size-6" />
                </div>
                <div>
                    <flux:text size="sm">{{ __('Closed') }}</flux:text>
                    <flux:heading size="lg" class="tabular-nums">{{ $this->stats['closed'] }}</flux:heading>
                </div>
            </flux:card>
        @endif
    </div>

    <!-- Filter Bar -->
    <flux:card class="p-4 sm:p-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6">
            <div class="sm:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by ticket #, title, or user...') }}" icon="magnifying-glass"
                    clearable />
            </div>

            <div>
                <flux:select wire:model.live="status" placeholder="{{ __('Status') }}">
                    <flux:select.option value="all">{{ __('All Statuses') }}</flux:select.option>
                    @foreach (TicketStatus::cases() as $st)
                        <flux:select.option value="{{ $st->value }}">{{ $st->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:select wire:model.live="category" placeholder="{{ __('Category') }}">
                    <flux:select.option value="all">{{ __('All Categories') }}</flux:select.option>
                    @foreach (TicketCategory::cases() as $cat)
                        <flux:select.option value="{{ $cat->value }}">{{ $cat->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if (auth()->user()->isIT())
                <div>
                    <flux:select wire:model.live="priority" placeholder="{{ __('Priority') }}">
                        <flux:select.option value="all">{{ __('All Priorities') }}</flux:select.option>
                        @foreach (TicketPriority::cases() as $pri)
                            <flux:select.option value="{{ $pri->value }}">{{ $pri->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:select wire:model.live="division" placeholder="{{ __('Division') }}">
                        <flux:select.option value="all">{{ __('All Divisions') }}</flux:select.option>
                        @foreach (Division::cases() as $div)
                            <flux:select.option value="{{ $div->value }}">{{ $div->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </flux:card>

    <!-- Tickets Table Card -->
    <flux:card class="p-4 sm:p-6">
        @if ($this->tickets->isEmpty())
            <div class="flex flex-col items-center justify-center py-12 px-6 text-center">
                <div
                    class="flex size-14 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 mb-3">
                    <flux:icon name="ticket" class="size-8" />
                </div>
                <flux:heading size="lg">{{ __('No tickets found') }}</flux:heading>
                <flux:text class="mt-1 max-w-sm text-sm">
                    {{ __('No helpdesk tickets match the current filters. Clear the search or submit a new ticket.') }}
                </flux:text>
                <div class="mt-5 flex items-center gap-3">
                    <flux:button wire:click="resetFilters" variant="subtle" size="sm">
                        {{ __('Reset Filters') }}
                    </flux:button>
                    <flux:button href="{{ route('tickets.create') }}" variant="primary" size="sm" wire:navigate>
                        {{ __('Submit Issue') }}
                    </flux:button>
                </div>
            </div>
        @else
            <flux:table :paginate="$this->tickets">
                <flux:table.columns>
                    <flux:table.column>{{ __('Ticket') }}</flux:table.column>
                    @if (auth()->user()->isIT())
                        <flux:table.column>{{ __('Submitter') }}</flux:table.column>
                    @endif
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Priority') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Assigned To') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->tickets as $ticket)
                        <flux:table.row :key="$ticket->id"
                            class="group hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40 transition-colors">
                            <flux:table.cell variant="strong">
                                <div class="flex flex-col gap-0.5 max-w-sm">
                                    <flux:link :href="route('tickets.show', $ticket)" wire:navigate
                                        class="font-medium text-sm text-zinc-900 dark:text-zinc-100 hover:text-sky-600 dark:hover:text-sky-400 line-clamp-1">
                                        {{ $ticket->title }}
                                    </flux:link>
                                    <span
                                        class="font-mono text-xs font-normal text-zinc-400 dark:text-zinc-500 tracking-wide">
                                        {{ $ticket->ticket_number }}
                                    </span>
                                </div>
                            </flux:table.cell>

                            @if (auth()->user()->isIT())
                                <flux:table.cell>
                                    <div class="flex items-center gap-2.5">
                                        <flux:avatar size="xs" :name="$ticket->user->name"
                                            :initials="$ticket->user->initials()" />
                                        <div class="flex flex-col">
                                            <span
                                                class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $ticket->user->name }}</span>
                                            <flux:badge size="xs" color="zinc" class="w-fit mt-0.5">
                                                {{ $ticket->division->label() }}
                                            </flux:badge>
                                        </div>
                                    </div>
                                </flux:table.cell>
                            @endif

                            <flux:table.cell class="whitespace-nowrap">
                                {{ $ticket->category->label() }}
                            </flux:table.cell>

                            <flux:table.cell class="py-0">
                                <flux:badge size="sm" :color="$ticket->priority->color()">
                                    {{ $ticket->priority->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell class="py-0">
                                <flux:badge size="sm" :color="$ticket->status->color()"
                                    :icon="$ticket->status->icon()">
                                    {{ $ticket->status->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                @if ($ticket->assignedTo)
                                    <div class="flex items-center gap-2">
                                        <flux:avatar size="xs" :name="$ticket->assignedTo->name"
                                            :initials="$ticket->assignedTo->initials()" />
                                        <span
                                            class="text-sm text-zinc-700 dark:text-zinc-300">{{ $ticket->assignedTo->name }}</span>
                                    </div>
                                @else
                                    <flux:text size="xs" class="italic text-zinc-400">{{ __('Unassigned') }}
                                    </flux:text>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                <flux:text size="xs" class="text-zinc-500">
                                    {{ $ticket->created_at->diffForHumans() }}</flux:text>
                            </flux:table.cell>

                            <flux:table.cell class="py-0 text-end">
                                <flux:button href="{{ route('tickets.show', $ticket) }}" variant="ghost"
                                    size="sm" icon="chevron-right" wire:navigate
                                    aria-label="{{ __('View ticket') }}"
                                    class="opacity-60 group-hover:opacity-100 group-hover:translate-x-0.5 transition" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
