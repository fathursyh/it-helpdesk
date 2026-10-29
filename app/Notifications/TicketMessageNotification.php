<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $ticketMessage
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
        $senderName = $this->ticketMessage->user->name;
        $excerpt = Str::limit($this->ticketMessage->content, 200);

        return (new MailMessage)
            ->subject("[IT Helpdesk] New Update on Ticket #{$this->ticket->ticket_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$senderName} added a new update to ticket **{$this->ticket->ticket_number} - {$this->ticket->title}**:")
            ->line("\"{$excerpt}\"")
            ->action('View Ticket Discussion', route('tickets.show', $this->ticket));
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
            'message_id' => $this->ticketMessage->id,
            'sender_id' => $this->ticketMessage->user_id,
            'sender_name' => $this->ticketMessage->user->name,
            'message' => "New reply on #{$this->ticket->ticket_number} from {$this->ticketMessage->user->name}",
        ];
    }
}
