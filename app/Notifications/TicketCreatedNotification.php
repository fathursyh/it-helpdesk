<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket) {}

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
            ->subject("[IT Helpdesk] New Ticket: {$this->ticket->ticket_number} - {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new IT ticket has been submitted by {$this->ticket->user->name} ({$this->ticket->division->label()}).")
            ->line("**Category:** {$this->ticket->category->label()}")
            ->line("**Priority:** {$this->ticket->priority->label()}")
            ->line('**Description:**')
            ->line($this->ticket->description)
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
            'submitter_name' => $this->ticket->user->name,
            'division' => $this->ticket->division->value,
            'category' => $this->ticket->category->value,
            'priority' => $this->ticket->priority->value,
            'message' => "New ticket submitted: {$this->ticket->title}",
        ];
    }
}
