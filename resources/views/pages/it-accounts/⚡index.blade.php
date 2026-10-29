<?php

use App\Enums\Division;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('IT Staff Accounts')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    // Create Modal State
    public bool $showCreateModal = false;
    public string $newName = '';
    public string $newEmail = '';
    public string $newPassword = '';
    public string $newPassword_confirmation = '';
    public string $newDivision = 'it';

    // Edit Modal State
    public bool $showEditModal = false;
    public ?int $editingUserId = null;
    public string $editName = '';
    public string $editEmail = '';
    public string $editDivision = 'it';
    public string $editPassword = '';

    // Promote Modal State
    public bool $showPromoteModal = false;
    public ?int $promoteEmployeeId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isIT(), 403, 'Unauthorized. Only IT staff can access IT account management.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * List of IT staff members.
     */
    #[Computed]
    public function itStaffMembers(): LengthAwarePaginator
    {
        return User::query()
            ->where('role', UserRole::ITStaff)
            ->withCount([
                'assignedTickets',
                'assignedTickets as open_assigned_tickets_count' => function (Builder $query): void {
                    $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress]);
                },
            ])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * List of employees available for promotion to IT staff.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function availableEmployees(): Collection
    {
        return User::where('role', UserRole::Employee)
            ->orderBy('name')
            ->get();
    }

    /**
     * Summary metrics.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'total_it' => User::where('role', UserRole::ITStaff)->count(),
            'active_assigned_tickets' => Ticket::whereNotNull('assigned_to_id')
                ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                ->count(),
            'total_employees' => User::where('role', UserRole::Employee)->count(),
        ];
    }

    /**
     * Create a new IT account.
     */
    public function createItAccount(): void
    {
        abort_unless(Auth::user()?->isIT(), 403);

        $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newEmail' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'newPassword' => ['required', 'string', 'min:8', 'same:newPassword_confirmation'],
            'newDivision' => ['required', Rule::enum(Division::class)],
        ], [
            'newEmail.unique' => __('This email address is already registered.'),
            'newPassword.same' => __('Password confirmation does not match.'),
        ]);

        User::create([
            'name' => $this->newName,
            'email' => $this->newEmail,
            'password' => Hash::make($this->newPassword),
            'role' => UserRole::ITStaff,
            'division' => Division::from($this->newDivision),
            'email_verified_at' => now(),
        ]);

        $this->reset(['newName', 'newEmail', 'newPassword', 'newPassword_confirmation', 'newDivision']);
        $this->showCreateModal = false;

        Flux::toast(variant: 'success', text: __('New IT staff account created successfully.'));
    }

    /**
     * Open edit modal for an IT staff member.
     */
    public function openEditModal(int $userId): void
    {
        abort_unless(Auth::user()?->isIT(), 403);

        $user = User::where('role', UserRole::ITStaff)->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editDivision = $user->division->value;
        $this->editPassword = '';

        $this->showEditModal = true;
    }

    /**
     * Save edited IT staff account details.
     */
    public function saveEditedItAccount(): void
    {
        abort_unless(Auth::user()?->isIT(), 403);

        $user = User::where('role', UserRole::ITStaff)->findOrFail($this->editingUserId);

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editEmail' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            'editDivision' => ['required', Rule::enum(Division::class)],
            'editPassword' => ['nullable', 'string', 'min:8'],
        ]);

        $user->name = $this->editName;
        $user->email = $this->editEmail;
        $user->division = Division::from($this->editDivision);

        if (! empty($this->editPassword)) {
            $user->password = Hash::make($this->editPassword);
        }

        $user->save();

        $this->showEditModal = false;
        $this->reset(['editingUserId', 'editName', 'editEmail', 'editDivision', 'editPassword']);

        Flux::toast(variant: 'success', text: __('IT account updated successfully.'));
    }

    /**
     * Promote an existing employee to IT staff.
     */
    public function promoteEmployee(): void
    {
        abort_unless(Auth::user()?->isIT(), 403);

        $this->validate([
            'promoteEmployeeId' => ['required', 'exists:users,id'],
        ]);

        $employee = User::where('role', UserRole::Employee)->findOrFail($this->promoteEmployeeId);

        $employee->update([
            'role' => UserRole::ITStaff,
            'division' => Division::It,
        ]);

        $this->showPromoteModal = false;
        $this->reset('promoteEmployeeId');

        Flux::toast(variant: 'success', text: __(':name has been promoted to IT Staff.', ['name' => $employee->name]));
    }

    /**
     * Demote an IT staff member back to a regular employee.
     */
    public function demoteItStaff(int $userId): void
    {
        abort_unless(Auth::user()?->isIT(), 403);

        $target = User::where('role', UserRole::ITStaff)->findOrFail($userId);

        // Safeguard 1: Cannot demote yourself
        if ($target->id === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot demote your own account.'));
            return;
        }

        // Safeguard 2: Cannot demote if they are the last remaining IT member
        $itCount = User::where('role', UserRole::ITStaff)->count();
        if ($itCount <= 1) {
            Flux::toast(variant: 'danger', text: __('Cannot demote the last remaining IT staff account.'));
            return;
        }

        // Unassign any active open tickets assigned to this staff member
        Ticket::where('assigned_to_id', $target->id)
            ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
            ->update(['assigned_to_id' => null]);

        $target->update([
            'role' => UserRole::Employee,
            'division' => Division::Other,
        ]);

        Flux::toast(variant: 'success', text: __(':name has been demoted to Employee.', ['name' => $target->name]));
    }
}; ?>

<div class="flex flex-col gap-6 w-full">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('IT Staff Accounts') }}</flux:heading>
            <flux:subheading>
                {{ __('Manage accounts authorized to triage tickets, assign issues, and communicate internally.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($this->availableEmployees->isNotEmpty())
                <flux:button wire:click="$set('showPromoteModal', true)" variant="subtle" icon="arrow-up-circle">
                    {{ __('Promote Employee') }}
                </flux:button>
            @endif

            <flux:button wire:click="$set('showCreateModal', true)" variant="primary" icon="user-plus">
                {{ __('New IT Account') }}
            </flux:button>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <flux:card class="flex items-center gap-4 p-5">
            <div class="flex size-11 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                <flux:icon name="users" class="size-6" />
            </div>
            <div>
                <flux:text size="sm">{{ __('Total IT Staff') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ $this->stats['total_it'] }}</flux:heading>
            </div>
        </flux:card>

        <flux:card class="flex items-center gap-4 p-5">
            <div class="flex size-11 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                <flux:icon name="ticket" class="size-6" />
            </div>
            <div>
                <flux:text size="sm">{{ __('Active Assigned Tickets') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ $this->stats['active_assigned_tickets'] }}</flux:heading>
            </div>
        </flux:card>

        <flux:card class="flex items-center gap-4 p-5">
            <div class="flex size-11 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon name="user-group" class="size-6" />
            </div>
            <div>
                <flux:text size="sm">{{ __('Total Company Employees') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ $this->stats['total_employees'] }}</flux:heading>
            </div>
        </flux:card>
    </div>

    <!-- Filter / Search -->
    <flux:card class="p-4 sm:p-5">
        <div class="max-w-md">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search IT staff by name or email...') }}"
                icon="magnifying-glass"
                clearable
            />
        </div>
    </flux:card>

    <!-- IT Staff Accounts Table -->
    <flux:card>
        @if ($this->itStaffMembers->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="flex size-14 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 mb-3">
                    <flux:icon name="users" class="size-8" />
                </div>
                <flux:heading size="lg">{{ __('No IT staff found') }}</flux:heading>
                <flux:text class="mt-1 max-w-sm text-sm">
                    {{ __('No IT members match your search criteria.') }}
                </flux:text>
            </div>
        @else
            <flux:table :paginate="$this->itStaffMembers">
                <flux:table.columns>
                    <flux:table.column class="py-3.5">{{ __('IT Member') }}</flux:table.column>
                    <flux:table.column class="py-3.5">{{ __('Email') }}</flux:table.column>
                    <flux:table.column class="py-3.5">{{ __('Division') }}</flux:table.column>
                    <flux:table.column class="py-3.5">{{ __('Assigned Tickets') }}</flux:table.column>
                    <flux:table.column class="py-3.5">{{ __('Date Added') }}</flux:table.column>
                    <flux:table.column class="py-3.5 text-end"></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->itStaffMembers as $member)
                        <flux:table.row :key="$member->id" class="group hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40 transition-colors">
                            <flux:table.cell class="py-4">
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :name="$member->name" :initials="$member->initials()" />
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $member->name }}</span>
                                            @if ($member->id === auth()->id())
                                                <flux:badge size="xs" color="sky">{{ __('You') }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="py-4">
                                <span class="text-sm text-zinc-600 dark:text-zinc-300 font-mono">{{ $member->email }}</span>
                            </flux:table.cell>

                            <flux:table.cell class="py-4">
                                <flux:badge size="sm" color="zinc">
                                    {{ $member->division->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell class="py-4">
                                <div class="flex items-center gap-2">
                                    @if ($member->open_assigned_tickets_count > 0)
                                        <flux:badge size="sm" color="amber">
                                            {{ $member->open_assigned_tickets_count }} {{ __('Active') }}
                                        </flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">
                                            0 {{ __('Active') }}
                                        </flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-400">({{ $member->assigned_tickets_count }} {{ __('total') }})</span>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="py-4 whitespace-nowrap">
                                <flux:text size="xs" class="text-zinc-500">{{ $member->created_at->format('M d, Y') }}</flux:text>
                            </flux:table.cell>

                            <flux:table.cell class="py-4 text-end">
                                <div class="flex items-center justify-end gap-1.5">
                                    <flux:button wire:click="openEditModal({{ $member->id }})" variant="ghost" size="sm" icon="pencil-square" aria-label="{{ __('Edit IT member') }}">
                                        {{ __('Edit') }}
                                    </flux:button>

                                    @if ($member->id !== auth()->id() && $this->itStaffMembers->total() > 1)
                                        <flux:button
                                            wire:click="demoteItStaff({{ $member->id }})"
                                            wire:confirm="{{ __('Are you sure you want to demote :name to a regular employee? Any open tickets assigned to them will become unassigned.', ['name' => $member->name]) }}"
                                            variant="ghost"
                                            size="sm"
                                            class="text-red-600 hover:text-red-700 dark:text-red-400"
                                            icon="arrow-down-circle"
                                        >
                                            {{ __('Demote') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <!-- Create IT Staff Modal -->
    <flux:modal wire:model.self="showCreateModal" class="max-w-lg">
        <form wire:submit="createItAccount" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create New IT Account') }}</flux:heading>
                <flux:subheading>
                    {{ __('Create a credentialed IT account with full access to triage, prioritize, and manage helpdesk tickets.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Full Name') }}</flux:label>
                <flux:input wire:model="newName" type="text" required placeholder="{{ __('e.g., Alex Johnson') }}" />
                <flux:error name="newName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Email Address') }}</flux:label>
                <flux:input wire:model="newEmail" type="email" required placeholder="{{ __('it.staff@company.com') }}" />
                <flux:error name="newEmail" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Division / Department') }}</flux:label>
                <flux:select wire:model="newDivision" required>
                    @foreach (Division::cases() as $div)
                        <flux:select.option value="{{ $div->value }}">{{ $div->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="newDivision" />
            </flux:field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Password') }}</flux:label>
                    <flux:input wire:model="newPassword" type="password" required viewable placeholder="{{ __('Minimum 8 chars') }}" />
                    <flux:error name="newPassword" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Confirm Password') }}</flux:label>
                    <flux:input wire:model="newPassword_confirmation" type="password" required viewable placeholder="{{ __('Confirm password') }}" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="$set('showCreateModal', false)" variant="subtle" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="user-plus">
                    {{ __('Create Account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Edit IT Staff Modal -->
    <flux:modal wire:model.self="showEditModal" class="max-w-lg">
        <form wire:submit="saveEditedItAccount" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit IT Account') }}</flux:heading>
                <flux:subheading>
                    {{ __('Update staff details or set a new password.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Full Name') }}</flux:label>
                <flux:input wire:model="editName" type="text" required />
                <flux:error name="editName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Email Address') }}</flux:label>
                <flux:input wire:model="editEmail" type="email" required />
                <flux:error name="editEmail" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Division / Department') }}</flux:label>
                <flux:select wire:model="editDivision" required>
                    @foreach (Division::cases() as $div)
                        <flux:select.option value="{{ $div->value }}">{{ $div->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editDivision" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Reset Password (Leave blank to keep current)') }}</flux:label>
                <flux:input wire:model="editPassword" type="password" viewable placeholder="{{ __('New password (optional)') }}" />
                <flux:error name="editPassword" />
            </flux:field>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="$set('showEditModal', false)" variant="subtle" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Promote Existing Employee Modal -->
    <flux:modal wire:model.self="showPromoteModal" class="max-w-lg">
        <form wire:submit="promoteEmployee" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Promote Employee to IT Staff') }}</flux:heading>
                <flux:subheading>
                    {{ __('Select an existing registered employee to grant them IT staff access.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Select Employee') }}</flux:label>
                <flux:select wire:model="promoteEmployeeId" required placeholder="{{ __('Choose an employee...') }}">
                    @foreach ($this->availableEmployees as $emp)
                        <flux:select.option value="{{ $emp->id }}">
                            {{ $emp->name }} ({{ $emp->email }}) - {{ $emp->division->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="promoteEmployeeId" />
            </flux:field>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="$set('showPromoteModal', false)" variant="subtle" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-up-circle">
                    {{ __('Promote to IT Staff') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
