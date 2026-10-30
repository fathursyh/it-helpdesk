<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketMessageNotification;
use App\Notifications\TicketStatusChangedNotification;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Ticket Details')] class extends Component {
    use AuthorizesRequests, WithFileUploads;

    public Ticket $ticket;
    public string $replyContent = '';
    public bool $isInternal = false;
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $replyAttachments = [];

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket;
        $this->authorize('view', $this->ticket);
    }

    /**
     * IT staff list for assignment dropdown.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function itStaffMembers(): Collection
    {
        return User::where('role', UserRole::ITStaff)->orderBy('name')->get();
    }

    /**
     * Timeline messages visible to the current user.
     *
     * @return Collection<int, TicketMessage>
     */
    #[Computed]
    public function timeline(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        $query = $this->ticket->messages()->with(['user', 'attachments']);

        if (! $user->isIT()) {
            $query->publicOnly();
        }

        return $query->get();
    }

    /**
     * Update ticket priority (IT staff only).
     */
    public function updatePriority(string $priorityValue): void
    {
        $this->authorize('updatePriority', $this->ticket);

        $priority = TicketPriority::from($priorityValue);
        $this->ticket->update(['priority' => $priority]);

        Flux::toast(variant: 'success', text: __('Priority updated to :priority', ['priority' => $priority->label()]));
    }

    /**
     * Assign ticket to an IT staff member (IT staff only).
     */
    public function assignStaff(?string $userId): void
    {
        $this->authorize('assign', $this->ticket);

        $assignedId = (! empty($userId) && $userId !== 'none') ? (int) $userId : null;
        $this->ticket->update(['assigned_to_id' => $assignedId]);

        $this->ticket->load('assignedTo');
        $assigneeName = $this->ticket->assignedTo?->name ?? 'Unassigned';

        Flux::toast(variant: 'success', text: __('Assigned to :name', ['name' => $assigneeName]));
    }

    /**
     * Change ticket status.
     */
    public function updateStatus(string $statusValue): void
    {
        $newStatus = TicketStatus::from($statusValue);
        $this->authorize('changeStatus', [$this->ticket, $newStatus]);

        $oldStatus = $this->ticket->status;

        $updates = ['status' => $newStatus];
        if ($newStatus === TicketStatus::Resolved) {
            $updates['resolved_at'] = now();
        } elseif ($newStatus === TicketStatus::Closed) {
            $updates['closed_at'] = now();
        } elseif ($newStatus === TicketStatus::Open) {
            if (in_array($oldStatus, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
                $updates['reopened_at'] = now();
                $updates['reopened_count'] = ($this->ticket->reopened_count ?? 0) + 1;
            }
            $updates['resolved_at'] = null;
            $updates['closed_at'] = null;
        }

        $this->ticket->update($updates);

        // Notify submitter if changed by someone else
        if ($this->ticket->user_id !== Auth::id()) {
            $this->ticket->user->notify(new TicketStatusChangedNotification($this->ticket, $oldStatus, $newStatus));
        }

        Flux::toast(variant: 'success', text: __('Ticket status changed to :status', ['status' => $newStatus->label()]));
    }

    /**
     * Close own ticket (for submitters).
     */
    public function closeTicket(): void
    {
        $this->updateStatus(TicketStatus::Closed->value);
    }

    /**
     * Reopen ticket (for submitters).
     */
    public function reopenTicket(): void
    {
        $this->updateStatus(TicketStatus::Open->value);
    }

    /**
     * Post a reply or internal note.
     */
    public function submitReply(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $isInternal = $user->isIT() ? $this->isInternal : false;

        $this->authorize('addMessage', [$this->ticket, $isInternal]);

        $this->validate([
            'replyContent' => ['required', 'string', 'min:2'],
            'replyAttachments.*' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,txt,zip'],
        ]);

        $message = TicketMessage::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $user->id,
            'content' => $this->replyContent,
            'is_internal' => $isInternal,
        ]);

        // Upload attachments
        foreach ($this->replyAttachments as $file) {
            $path = $file->store('attachments', 'public');
            TicketAttachment::create([
                'ticket_id' => $this->ticket->id,
                'ticket_message_id' => $message->id,
                'user_id' => $user->id,
                'filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            ]);
        }

        // Track first response time by IT staff
        if ($user->isIT() && $this->ticket->first_responded_at === null) {
            $this->ticket->update(['first_responded_at' => now()]);
        }

        // Send notifications for public updates
        if (! $isInternal) {
            if ($user->isIT()) {
                // IT replied -> notify submitter
                $this->ticket->user->notify(new TicketMessageNotification($this->ticket, $message));
            } else {
                // Submitter replied -> notify assigned IT or all IT
                if ($this->ticket->assignedTo) {
                    $this->ticket->assignedTo->notify(new TicketMessageNotification($this->ticket, $message));
                } else {
                    $itStaff = User::where('role', UserRole::ITStaff)->get();
                    if ($itStaff->isNotEmpty()) {
                        Notification::send($itStaff, new TicketMessageNotification($this->ticket, $message));
                    }
                }
            }
        }

        $this->reset(['replyContent', 'isInternal', 'replyAttachments']);
        $this->dispatch('reply-submitted');

        Flux::toast(variant: 'success', text: $isInternal ? __('Internal note added.') : __('Reply sent successfully.'));
    }

    public function removeReplyAttachment(int $index): void
    {
        if (isset($this->replyAttachments[$index])) {
            array_splice($this->replyAttachments, $index, 1);
        }
    }
}; ?>

<div class="mx-auto max-w-5xl w-full flex flex-col gap-6" wire:poll.30s>
    <!-- Breadcrumbs -->
    <div class="flex items-center justify-between">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="{{ route('tickets.index') }}" wire:navigate>{{ __('Tickets') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $ticket->ticket_number }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center gap-2">
            @if ($ticket->isClosed())
                <flux:button wire:click="reopenTicket" variant="subtle" size="sm" icon="arrow-path">
                    {{ __('Reopen Ticket') }}
                </flux:button>
            @else
                @if ($ticket->user_id === auth()->id())
                    <flux:button wire:click="closeTicket" variant="subtle" size="sm" icon="check">
                        {{ __('Close Ticket') }}
                    </flux:button>
                @endif
            @endif
        </div>
    </div>

    <!-- Ticket Summary Card -->
    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between border-b border-zinc-200 pb-4 dark:border-zinc-700">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-semibold text-zinc-500">{{ $ticket->ticket_number }}</span>
                    <flux:badge size="sm" :color="$ticket->status->color()" :icon="$ticket->status->icon()">
                        {{ $ticket->status->label() }}
                    </flux:badge>
                    <flux:badge size="sm" :color="$ticket->priority->color()">
                        {{ $ticket->priority->label() }} {{ __('Priority') }}
                    </flux:badge>
                    <flux:badge size="sm" color="zinc">
                        {{ $ticket->category->label() }}
                    </flux:badge>
                </div>
                <flux:heading size="xl" class="mt-1">{{ $ticket->title }}</flux:heading>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <span>{{ __('Submitted by') }} <strong class="text-zinc-700 dark:text-zinc-200">{{ $ticket->user->name }}</strong> ({{ $ticket->division->label() }})</span>
                    <span>&bull;</span>
                    <span>{{ $ticket->created_at->format('M d, Y H:i') }} ({{ $ticket->created_at->diffForHumans() }})</span>
                    @if ($ticket->assignedTo)
                        <span>&bull;</span>
                        <span>{{ __('Assigned to') }} <strong class="text-zinc-700 dark:text-zinc-200">{{ $ticket->assignedTo->name }}</strong></span>
                    @endif
                </div>
            </div>

            <!-- IT Quick Action Bar -->
            @if (auth()->user()->isIT())
                <div class="flex flex-wrap items-center gap-2 rounded-xl bg-zinc-50 p-3 border border-zinc-200 dark:bg-zinc-800/70 dark:border-zinc-700">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ __('Status') }}</span>
                        <flux:select wire:change="updateStatus($event.target.value)" size="sm">
                            @foreach (TicketStatus::cases() as $st)
                                <flux:select.option value="{{ $st->value }}" :selected="$ticket->status === $st">
                                    {{ $st->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ __('Priority') }}</span>
                        <flux:select wire:change="updatePriority($event.target.value)" size="sm">
                            @foreach (TicketPriority::cases() as $pr)
                                <flux:select.option value="{{ $pr->value }}" :selected="$ticket->priority === $pr">
                                    {{ $pr->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ __('Assignee') }}</span>
                        <flux:select wire:change="assignStaff($event.target.value)" size="sm">
                            <flux:select.option value="none" :selected="$ticket->assigned_to_id === null">{{ __('Unassigned') }}</flux:select.option>
                            @foreach ($this->itStaffMembers as $staff)
                                <flux:select.option value="{{ $staff->id }}" :selected="$ticket->assigned_to_id === $staff->id">
                                    {{ $staff->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            @endif
        </div>

        <!-- Issue Description -->
        <div class="prose prose-zinc dark:prose-invert max-w-none text-sm text-zinc-800 dark:text-zinc-200">
            {!! nl2br(e($ticket->description)) !!}
        </div>

        <!-- Initial Attachments (Attached at Ticket Creation) -->
        @php
            $initialAttachments = $ticket->attachments->whereNull('ticket_message_id');
        @endphp
        @if ($initialAttachments->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider block mb-2">{{ __('Attachments') }} ({{ $initialAttachments->count() }})</span>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
                    @foreach ($initialAttachments as $att)
                        <a href="{{ $att->url() }}" target="_blank" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2 text-xs hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800 transition">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300">
                                @if ($att->isImage())
                                    <flux:icon name="photo" class="size-4" />
                                @else
                                    <flux:icon name="document" class="size-4" />
                                @endif
                            </div>
                            <div class="overflow-hidden">
                                <span class="block truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $att->filename }}</span>
                                <span class="text-zinc-400">{{ $att->formattedSize() }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </flux:card>

    <!-- Discussion & Updates Timeline -->
    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <flux:icon name="chat-bubble-left-right" class="size-5 text-zinc-500" />
            <flux:heading size="lg">{{ __('Discussion & Updates') }}</flux:heading>
            <span class="text-xs text-zinc-400">({{ $this->timeline->count() }})</span>
        </div>

        @if ($this->timeline->isEmpty())
            <flux:card class="text-center py-8">
                <flux:text class="text-zinc-500 text-sm">
                    {{ __('No updates yet. Send a message below to start the conversation.') }}
                </flux:text>
            </flux:card>
        @else
            <div class="flex flex-col gap-4">
                @foreach ($this->timeline as $msg)
                    @if ($msg->is_internal)
                        <!-- Internal IT Note (visible only to IT) -->
                        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <flux:avatar size="xs" :name="$msg->user->name" :initials="$msg->user->initials()" />
                                    <span class="text-xs font-semibold text-amber-900 dark:text-amber-200">{{ $msg->user->name }}</span>
                                    <flux:badge size="xs" color="amber" icon="lock-closed">
                                        {{ __('Internal IT Note') }}
                                    </flux:badge>
                                </div>
                                <span class="text-xs text-amber-700/70 dark:text-amber-300/60">{{ $msg->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="text-sm text-amber-900 dark:text-amber-100 whitespace-pre-line pl-6">
                                {{ $msg->content }}
                            </div>

                            @if ($msg->attachments->isNotEmpty())
                                <div class="flex flex-wrap gap-2 mt-3 pl-6">
                                    @foreach ($msg->attachments as $mAtt)
                                        <a href="{{ $mAtt->url() }}" target="_blank" class="flex items-center gap-2 rounded bg-amber-100/60 px-2.5 py-1 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-200 hover:underline">
                                            <flux:icon name="paper-clip" class="size-3.5" />
                                            <span class="truncate max-w-37.5">{{ $mAtt->filename }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <!-- Public Reply -->
                        <div class="flex gap-3 {{ $msg->user_id === auth()->id() ? 'justify-end' : 'justify-start' }}">
                            <div class="flex flex-col max-w-2xl {{ $msg->user_id === auth()->id() ? 'items-end' : 'items-start' }}">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $msg->user->name }}</span>
                                    @if ($msg->user->isIT())
                                        <flux:badge size="xs" color="sky">{{ __('IT Staff') }}</flux:badge>
                                    @else
                                        <flux:badge size="xs" color="zinc">{{ $msg->user->division->label() }}</flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-400">{{ $msg->created_at->diffForHumans() }}</span>
                                </div>

                                <div class="rounded-xl p-4 text-sm {{ $msg->user->isIT() ? 'bg-sky-50 text-zinc-900 border border-sky-200 dark:bg-sky-950/40 dark:text-zinc-100 dark:border-sky-800' : 'bg-white text-zinc-900 border border-zinc-200 dark:bg-zinc-800 dark:text-zinc-100 dark:border-zinc-700' }} shadow-sm">
                                    <div class="whitespace-pre-line">{{ $msg->content }}</div>

                                    @if ($msg->attachments->isNotEmpty())
                                        <div class="flex flex-wrap gap-2 mt-3 pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60">
                                            @foreach ($msg->attachments as $mAtt)
                                                <a href="{{ $mAtt->url() }}" target="_blank" class="flex items-center gap-2 rounded bg-zinc-100 px-2.5 py-1 text-xs text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200 hover:underline">
                                                    <flux:icon name="paper-clip" class="size-3.5" />
                                                    <span class="truncate max-w-37.5">{{ $mAtt->filename }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    <!-- Reply / Message Input Box -->
    @if ($ticket->isClosed() && !auth()->user()->isIT())
        <flux:card class="text-center py-6 bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700">
            <flux:icon name="archive-box" class="size-8 mx-auto text-zinc-400 mb-2" />
            <flux:heading size="md">{{ __('This ticket is closed') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 max-w-md mx-auto mt-1 mb-3">
                {{ __('If your issue is not completely resolved or has re-occurred, you can reopen it to continue the discussion.') }}
            </flux:text>
            <flux:button wire:click="reopenTicket" variant="primary" size="sm" icon="arrow-path">
                {{ __('Reopen Ticket') }}
            </flux:button>
        </flux:card>
    @else
        <flux:card>
            <form wire:submit="submitReply" class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="md">{{ __('Post an Update / Reply') }}</flux:heading>
                    @if (auth()->user()->isIT())
                        <flux:checkbox
                            wire:model="isInternal"
                            label="{{ __('Internal Note (Only visible to IT Staff)') }}"
                        />
                    @endif
                </div>

                @if ($isInternal)
                    <div class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 flex items-center gap-2">
                        <flux:icon name="lock-closed" class="size-4 shrink-0" />
                        <span>{{ __('This note will only be visible to other IT staff members. The submitter will not see it.') }}</span>
                    </div>
                @endif

                <flux:field>
                    <flux:textarea
                        wire:model="replyContent"
                        rows="3"
                        required
                        placeholder="{{ $isInternal ? __('Write an internal technical note...') : __('Write a response or update...') }}"
                    />
                    <flux:error name="replyContent" />
                </flux:field>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <input
                            type="file"
                            wire:model="replyAttachments"
                            multiple
                            class="text-xs text-zinc-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-800 dark:file:bg-zinc-700 dark:file:text-zinc-200"
                        />
                        <div wire:loading wire:target="replyAttachments" class="text-xs text-sky-600 dark:text-sky-400 mt-1">
                            {{ __('Uploading file...') }}
                        </div>

                        @if (!empty($replyAttachments))
                            <div class="flex flex-wrap gap-2 mt-2">
                                @foreach ($replyAttachments as $index => $file)
                                    <div class="flex items-center gap-1.5 rounded border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                        <span class="truncate max-w-30">{{ $file->getClientOriginalName() }}</span>
                                        <button type="button" wire:click="removeReplyAttachment({{ $index }})" class="text-red-500">
                                            <flux:icon name="x-mark" class="size-3.5" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <flux:button type="submit" variant="primary" icon="paper-airplane">
                        {{ $isInternal ? __('Add Internal Note') : __('Send Reply') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
