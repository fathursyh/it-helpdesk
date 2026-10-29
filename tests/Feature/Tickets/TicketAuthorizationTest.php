<?php

use App\Enums\Division;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Livewire\Livewire;

test('employee can view their own ticket', function () {
    $employee = User::factory()->employee(Division::Hr)->create();
    $ticket = Ticket::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($employee)
        ->get(route('tickets.show', $ticket))
        ->assertOk();
});

test('employee cannot view another employee ticket', function () {
    $alice = User::factory()->employee(Division::Hr)->create();
    $bob = User::factory()->employee(Division::Operations)->create();
    $ticket = Ticket::factory()->create(['user_id' => $alice->id]);

    $this->actingAs($bob)
        ->get(route('tickets.show', $ticket))
        ->assertForbidden();
});

test('it staff can view any ticket', function () {
    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee(Division::Operations)->create();
    $ticket = Ticket::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($itStaff)
        ->get(route('tickets.show', $ticket))
        ->assertOk();
});

test('internal notes are hidden from employees', function () {
    $employee = User::factory()->employee(Division::Hr)->create();
    $itStaff = User::factory()->itStaff()->create();

    $ticket = Ticket::factory()->create(['user_id' => $employee->id]);

    TicketMessage::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $itStaff->id,
        'content' => 'Public response visible to employee',
        'is_internal' => false,
    ]);

    TicketMessage::factory()->internal()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $itStaff->id,
        'content' => 'Secret internal note for IT eyes only',
    ]);

    // Submitter view
    $this->actingAs($employee);
    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->assertSee('Public response visible to employee')
        ->assertDontSee('Secret internal note for IT eyes only');

    // IT Staff view
    $this->actingAs($itStaff);
    Livewire::test('pages::tickets.show', ['ticket' => $ticket])
        ->assertSee('Public response visible to employee')
        ->assertSee('Secret internal note for IT eyes only');
});

test('tickets index only shows own tickets to employees', function () {
    $alice = User::factory()->employee(Division::Hr)->create();
    $bob = User::factory()->employee(Division::Operations)->create();

    Ticket::factory()->create(['user_id' => $alice->id, 'title' => "Alice's Ticket"]);
    Ticket::factory()->create(['user_id' => $bob->id, 'title' => "Bob's Ticket"]);

    $this->actingAs($alice);
    Livewire::test('pages::tickets.index')
        ->assertSee("Alice's Ticket")
        ->assertDontSee("Bob's Ticket");
});

test('tickets index shows all tickets to IT staff', function () {
    $itStaff = User::factory()->itStaff()->create();
    $alice = User::factory()->employee(Division::Hr)->create();
    $bob = User::factory()->employee(Division::Operations)->create();

    Ticket::factory()->create(['user_id' => $alice->id, 'title' => "Alice's Ticket"]);
    Ticket::factory()->create(['user_id' => $bob->id, 'title' => "Bob's Ticket"]);

    $this->actingAs($itStaff);
    Livewire::test('pages::tickets.index')
        ->assertSee("Alice's Ticket")
        ->assertSee("Bob's Ticket");
});
