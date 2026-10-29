<?php

namespace App\Models;

use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $content
 * @property bool $is_internal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'ticket_id',
    'user_id',
    'content',
    'is_internal',
])]
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /**
     * The ticket this message belongs to.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The user who posted this message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Attachments attached to this message.
     *
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Scope messages to exclude internal IT notes for submitters.
     *
     * @param  Builder<TicketMessage>  $query
     */
    public function scopePublicOnly(Builder $query): void
    {
        $query->where('is_internal', false);
    }
}
