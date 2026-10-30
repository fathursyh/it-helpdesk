<?php

namespace App\Models;

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ticket_number
 * @property int $user_id
 * @property int|null $assigned_to_id
 * @property Division $division
 * @property TicketCategory $category
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property string $title
 * @property string $description
 * @property Carbon|null $resolved_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 * @property int $reopened_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'ticket_number',
    'user_id',
    'assigned_to_id',
    'division',
    'category',
    'priority',
    'status',
    'title',
    'description',
    'resolved_at',
    'first_responded_at',
    'closed_at',
    'reopened_at',
    'reopened_count',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            if (empty($ticket->ticket_number)) {
                $nextId = (static::max('id') ?? 0) + 1;
                $ticket->ticket_number = sprintf('IT-%s-%04d', now()->format('ym'), $nextId);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'division' => Division::class,
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopened_count' => 'integer',
        ];
    }

    /**
     * The submitter of the ticket.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The IT staff member assigned to the ticket.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * Messages and timeline entries for this ticket.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->oldest();
    }

    /**
     * Attachments belonging to this ticket.
     *
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Scope tickets visible to the given user.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        if (! $user->isIT()) {
            $query->where('user_id', $user->id);
        }
    }

    public function isOpen(): bool
    {
        return $this->status === TicketStatus::Open;
    }

    public function isInProgress(): bool
    {
        return $this->status === TicketStatus::InProgress;
    }

    public function isResolved(): bool
    {
        return $this->status === TicketStatus::Resolved;
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }
}
