<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Submit IT Issue')] class extends Component {
    use WithFileUploads;

    public string $title = '';
    public string $category = '';
    public string $division = '';
    public string $description = '';
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $attachments = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->division = $user->division->value;
        $this->category = TicketCategory::Other->value;
    }

    /**
     * Remove an attachment before submitting.
     */
    public function removeAttachment(int $index): void
    {
        if (isset($this->attachments[$index])) {
            array_splice($this->attachments, $index, 1);
        }
    }

    /**
     * Submit the ticket.
     */
    public function save()
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'division' => ['required', Rule::enum(Division::class)],
            'description' => ['required', 'string', 'min:10'],
            'attachments.*' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,txt,zip'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'title' => $this->title,
            'category' => $this->category,
            'division' => $this->division,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'description' => $this->description,
        ]);

        // Process attachments
        foreach ($this->attachments as $uploadedFile) {
            $path = $uploadedFile->store('attachments', 'public');
            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'filename' => $uploadedFile->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $uploadedFile->getSize(),
                'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
            ]);
        }

        // Notify IT staff
        $itStaff = User::where('role', UserRole::ITStaff)->get();
        if ($itStaff->isNotEmpty()) {
            Notification::send($itStaff, new TicketCreatedNotification($ticket));
        }

        Flux::toast(variant: 'success', text: __('Your ticket has been submitted. The IT team has been notified.'));

        return $this->redirectRoute('tickets.show', $ticket, navigate: true);
    }
}; ?>

<div class="mx-auto max-w-3xl w-full flex flex-col gap-6">
    <!-- Breadcrumb / Back Navigation -->
    <div class="flex items-center gap-2">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="{{ route('tickets.index') }}" wire:navigate>{{ __('Tickets') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('New Issue') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </div>

    <!-- Card Header -->
    <flux:card>
        <div class="border-b border-zinc-200 pb-4 dark:border-zinc-700">
            <flux:heading size="xl">{{ __('Submit an IT Issue') }}</flux:heading>
            <flux:subheading>
                {{ __('Provide detailed information about the technical problem you are experiencing.') }}
            </flux:subheading>
        </div>

        <form wire:submit="save" class="mt-6 flex flex-col gap-6">
            <!-- Issue Title -->
            <flux:field>
                <flux:label>{{ __('Issue Subject / Title') }}</flux:label>
                <flux:input
                    wire:model="title"
                    type="text"
                    required
                    placeholder="{{ __('e.g., Unable to connect to VPN, Printer offline on 2nd floor') }}"
                    autofocus
                />
                <flux:error name="title" />
            </flux:field>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <!-- Category -->
                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="category" required>
                        @foreach (TicketCategory::cases() as $cat)
                            <flux:select.option value="{{ $cat->value }}">{{ $cat->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="category" />
                </flux:field>

                <!-- Division -->
                <flux:field>
                    <flux:label>{{ __('Division / Department') }}</flux:label>
                    <flux:select wire:model="division" required>
                        @foreach (Division::cases() as $div)
                            <flux:select.option value="{{ $div->value }}">{{ $div->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="division" />
                </flux:field>
            </div>

            <!-- Description -->
            <flux:field>
                <flux:label>{{ __('Detailed Description') }}</flux:label>
                <flux:description>
                    {{ __('Please describe what you were doing, error messages shown, and steps to reproduce.') }}
                </flux:description>
                <flux:textarea
                    wire:model="description"
                    rows="6"
                    required
                    placeholder="{{ __('Describe the issue in detail...') }}"
                />
                <flux:error name="description" />
            </flux:field>

            <!-- Attachments / Screenshots -->
            <flux:field>
                <flux:label>{{ __('Attachments & Screenshots (Optional)') }}</flux:label>
                <flux:description>{{ __('Upload screenshots of error messages or relevant files (Max 10MB each).') }}</flux:description>

                <div class="mt-2 flex flex-col gap-3">
                    <input
                        type="file"
                        wire:model="attachments"
                        multiple
                        class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 dark:file:bg-zinc-700 dark:file:text-zinc-200"
                    />

                    <div wire:loading wire:target="attachments" class="text-xs text-sky-600 dark:text-sky-400">
                        {{ __('Uploading files, please wait...') }}
                    </div>

                    @if (!empty($attachments))
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach ($attachments as $index => $file)
                                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                    <flux:icon name="paper-clip" class="size-4 text-zinc-400" />
                                    <span class="truncate max-w-45 font-medium">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeAttachment({{ $index }})" class="text-red-500 hover:text-red-700">
                                        <flux:icon name="x-mark" class="size-4" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <flux:error name="attachments.*" />
            </flux:field>

            <!-- Submit Button -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button href="{{ route('tickets.index') }}" variant="subtle" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="paper-airplane">
                    {{ __('Submit Ticket') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
