<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('it staff can change priority and assign tickets', function () {
    $itStaff1 = User::factory()->itStaff()->create(['name' => 'IT Admin']);
    $itStaff2 = User::factory()->itStaff()->create(['name' => 'IT Specialist']);
    $employee = User::factory()->employee()->create();

    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'priority' => TicketPriority::Low,
        'assigned_to_id' => null,
    ]);

    $this->actingAs($itStaff1);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('updatePriority', TicketPriority::Urgent->value)
        ->call('assignStaff', (string) $itStaff2->id);

    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->assigned_to_id)->toBe($itStaff2->id);
});

test('employee cannot change ticket priority or assign staff', function () {
    $employee = User::factory()->employee()->create();
    $otherUser = User::factory()->employee()->create();
    $ticket = Ticket::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($employee);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('updatePriority', TicketPriority::Urgent->value)
        ->assertForbidden();

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('assignStaff', (string) $otherUser->id)
        ->assertForbidden();
});

test('it staff can update status and submitter is notified', function () {
    Notification::fake();

    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee()->create();

    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'status' => TicketStatus::Open,
    ]);

    $this->actingAs($itStaff);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('updateStatus', TicketStatus::InProgress->value);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::InProgress);

    Notification::assertSentTo($employee, TicketStatusChangedNotification::class);
});

test('submitter can close and reopen their own ticket', function () {
    $employee = User::factory()->employee()->create();
    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'status' => TicketStatus::Open,
    ]);

    $this->actingAs($employee);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('closeTicket');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at)->not->toBeNull();

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->call('reopenTicket');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)->toBeNull();
});
