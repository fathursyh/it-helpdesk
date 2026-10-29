<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view any tickets.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isIT() || $ticket->user_id === $user->id;
    }

    /**
     * Determine whether the user can create tickets.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the ticket metadata.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isIT();
    }

    /**
     * Determine whether the user can update ticket priority.
     */
    public function updatePriority(User $user, Ticket $ticket): bool
    {
        return $user->isIT();
    }

    /**
     * Determine whether the user can assign/reassign the ticket.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isIT();
    }

    /**
     * Determine whether the user can change ticket status.
     */
    public function changeStatus(User $user, Ticket $ticket, ?TicketStatus $newStatus = null): bool
    {
        if ($user->isIT()) {
            return true;
        }

        if ($ticket->user_id === $user->id) {
            if ($newStatus === null) {
                return true;
            }

            // Submitter can close their ticket, or reopen a resolved/closed ticket
            if (in_array($newStatus, [TicketStatus::Closed, TicketStatus::Resolved], true)) {
                return true;
            }

            if ($newStatus === TicketStatus::Open && ($ticket->isResolved() || $ticket->isClosed())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can add a reply or update to the ticket.
     */
    public function addMessage(User $user, Ticket $ticket, bool $isInternal = false): bool
    {
        if ($isInternal) {
            return $user->isIT();
        }

        if ($user->isIT()) {
            return true;
        }

        return $ticket->user_id === $user->id && ! $ticket->isClosed();
    }

    /**
     * Determine whether the user can view internal IT notes.
     */
    public function viewInternalNotes(User $user, Ticket $ticket): bool
    {
        return $user->isIT();
    }
}
