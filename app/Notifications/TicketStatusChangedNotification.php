<?php

namespace App\Notifications;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public TicketStatus $oldStatus,
        public TicketStatus $newStatus
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[IT Helpdesk] Ticket #{$this->ticket->ticket_number} Status Updated to {$this->newStatus->label()}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The status of your ticket **{$this->ticket->ticket_number} - {$this->ticket->title}** has changed:")
            ->line("**Previous Status:** {$this->oldStatus->label()}")
            ->line("**New Status:** {$this->newStatus->label()}")
            ->action('View Ticket', route('tickets.show', $this->ticket));
    }

    /**
     * Get the array representation of the notification for the database.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'title' => $this->ticket->title,
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
            'message' => "Ticket {$this->ticket->ticket_number} is now {$this->newStatus->label()}",
        ];
    }
}
