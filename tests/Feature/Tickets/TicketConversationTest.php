<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketMessageNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('submitter can reply to an open ticket', function () {
    Notification::fake();

    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee()->create();

    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Open,
    ]);

    $this->actingAs($employee);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->set('replyContent', 'I restarted my laptop but the issue still persists.')
        ->call('submitReply')
        ->assertHasNoErrors();

    $message = TicketMessage::where('ticket_id', $ticket->id)->latest()->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('I restarted my laptop but the issue still persists.')
        ->and($message->is_internal)->toBeFalse()
        ->and($message->user_id)->toBe($employee->id);

    Notification::assertSentTo($itStaff, TicketMessageNotification::class);
});

test('it staff can post internal notes and submitter is not notified', function () {
    Notification::fake();

    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee()->create();

    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
    ]);

    $this->actingAs($itStaff);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->set('replyContent', 'Checking server logs for port conflicts.')
        ->set('isInternal', true)
        ->call('submitReply')
        ->assertHasNoErrors();

    $message = TicketMessage::where('ticket_id', $ticket->id)->latest()->first();

    expect($message)->not->toBeNull()
        ->and($message->is_internal)->toBeTrue();

    Notification::assertNothingSent();
});

test('employee cannot post an internal note', function () {
    $employee = User::factory()->employee()->create();
    $ticket = Ticket::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($employee);

    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->set('replyContent', 'Attempting internal note')
        ->set('isInternal', true)
        ->call('submitReply')
        ->assertHasNoErrors();

    $message = TicketMessage::where('ticket_id', $ticket->id)->latest()->first();

    // The component forces $isInternal to false for non-IT users
    expect($message->is_internal)->toBeFalse();
});
