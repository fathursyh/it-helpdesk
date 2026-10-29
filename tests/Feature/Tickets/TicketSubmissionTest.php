<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('employee can view ticket submission page', function () {
    $employee = User::factory()->employee(Division::Hr)->create();

    $this->actingAs($employee)
        ->get(route('tickets.create'))
        ->assertOk();
});

test('employee can submit a new IT ticket with attachments', function () {
    Storage::fake('public');
    Notification::fake();

    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee(Division::Hr)->create();

    $file = UploadedFile::fake()->create('error_log.png', 500, 'image/png');

    $this->actingAs($employee);

    Livewire::test('pages::tickets.create')
        ->set('title', 'Cannot connect to company VPN')
        ->set('category', TicketCategory::Network->value)
        ->set('division', Division::Hr->value)
        ->set('description', 'Every time I try connecting to the VPN, it times out with error 800.')
        ->set('attachments', [$file])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ticket = Ticket::first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->title)->toBe('Cannot connect to company VPN')
        ->and($ticket->category)->toBe(TicketCategory::Network)
        ->and($ticket->division)->toBe(Division::Hr)
        ->and($ticket->priority)->toBe(TicketPriority::Medium)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->user_id)->toBe($employee->id)
        ->and($ticket->attachments)->toHaveCount(1);

    Notification::assertSentTo($itStaff, TicketCreatedNotification::class);
});

test('submitting a ticket validates required fields', function () {
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee);

    Livewire::test('pages::tickets.create')
        ->set('title', '')
        ->set('description', '')
        ->call('save')
        ->assertHasErrors(['title', 'description']);
});
