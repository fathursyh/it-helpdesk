<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $ticket_message_id
 * @property int $user_id
 * @property string $filename
 * @property string $file_path
 * @property int $file_size
 * @property string $mime_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'ticket_id',
    'ticket_message_id',
    'user_id',
    'filename',
    'file_path',
    'file_size',
    'mime_type',
])]
class TicketAttachment extends Model
{
    /**
     * The ticket this attachment belongs to.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The message this attachment is attached to, if any.
     *
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /**
     * The user who uploaded this attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the publicly accessible or storage URL.
     */
    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    /**
     * Check if the attachment is an image.
     */
    public function isImage(): bool
    {
        return Str::startsWith($this->mime_type, 'image/');
    }

    /**
     * Format the file size for humans (KB / MB).
     */
    public function formattedSize(): string
    {
        if ($this->file_size >= 1048576) {
            return number_format($this->file_size / 1048576, 1).' MB';
        }

        return number_format($this->file_size / 1024, 0).' KB';
    }
}
