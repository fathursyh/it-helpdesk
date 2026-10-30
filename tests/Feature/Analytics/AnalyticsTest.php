<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

test('guests are redirected to login when visiting analytics', function () {
    $this->get(route('analytics.index'))
        ->assertRedirect(route('login'));
});

test('regular employees cannot access analytics page', function () {
    $employee = User::factory()->employee(Division::Hr)->create();

    $this->actingAs($employee)
        ->get(route('analytics.index'))
        ->assertForbidden();
});

test('it staff can view analytics page and metrics', function () {
    $itStaff = User::factory()->itStaff()->create(['name' => 'Tech Specialist']);
    $employee = User::factory()->employee(Division::Operations)->create();

    $this->actingAs($itStaff)
        ->get(route('analytics.index'))
        ->assertOk();

    Livewire::actingAs($itStaff)
        ->test('pages::analytics.index')
        ->assertOk()
        ->assertSee('IT Helpdesk Analytics')
        ->assertSee('Tickets Submitted')
        ->assertSee('Tickets Resolved')
        ->assertSee('Tickets Closed')
        ->assertSee('First Response Time')
        ->assertSee('Reopened Tickets Rate')
        ->assertSee('Tech Specialist');
});

test('analytics computes monthly submitted, resolved, and closed counts correctly', function () {
    $itStaff = User::factory()->itStaff()->create();
    $employee = User::factory()->employee()->create();

    $targetMonth = Carbon::parse('2026-10-15 10:00:00');

    // Ticket submitted and resolved in target month
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Resolved,
        'created_at' => $targetMonth->copy()->subDays(2),
        'resolved_at' => $targetMonth->copy()->subDay(),
    ]);

    // Ticket submitted in target month, still open
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Open,
        'created_at' => $targetMonth->copy()->subHours(5),
    ]);

    // Ticket submitted in target month, closed
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Closed,
        'created_at' => $targetMonth->copy()->subDays(5),
        'closed_at' => $targetMonth->copy()->subDays(3),
    ]);

    // Ticket submitted in PREVIOUS month (should not count as submitted in this month)
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Open,
        'created_at' => Carbon::parse('2026-09-10 10:00:00'),
    ]);

    $component = Livewire::actingAs($itStaff)
        ->test('pages::analytics.index', ['month' => '2026-10']);

    $stats = $component->instance()->monthlyStats;

    expect($stats['submitted'])->toBe(3)
        ->and($stats['resolved'])->toBe(1)
        ->and($stats['closed'])->toBe(1)
        ->and($stats['completed'])->toBe(2);
});

test('analytics calculates first response time and reopened tickets rate accurately', function () {
    $itStaff = User::factory()->itStaff()->create(['name' => 'Support Hero']);
    $employee = User::factory()->employee()->create();

    $monthDate = Carbon::parse('2026-10-05 09:00:00');

    // Ticket 1: 30 minutes response time, resolved, never reopened
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Resolved,
        'created_at' => $monthDate,
        'first_responded_at' => $monthDate->copy()->addMinutes(30),
        'resolved_at' => $monthDate->copy()->addHours(2),
        'reopened_count' => 0,
    ]);

    // Ticket 2: 90 minutes response time, resolved then reopened
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itStaff->id,
        'status' => TicketStatus::Open,
        'created_at' => $monthDate->copy()->addDay(),
        'first_responded_at' => $monthDate->copy()->addDay()->addMinutes(90),
        'reopened_at' => $monthDate->copy()->addDays(2),
        'reopened_count' => 1,
    ]);

    $component = Livewire::actingAs($itStaff)
        ->test('pages::analytics.index', ['month' => '2026-10']);

    $stats = $component->instance()->monthlyStats;

    // Average FRT: (30 + 90) / 2 = 60 minutes
    expect($stats['avg_first_response_minutes'])->toBe(60.0)
        ->and($stats['responded_count'])->toBe(2)
        ->and($stats['reopened_count'])->toBe(1)
        ->and($stats['total_reopen_events'])->toBe(1);

    $formattedFRT = $component->instance()->formatDuration($stats['avg_first_response_minutes']);
    expect($formattedFRT)->toBe('1 hrs');
});

test('analytics counts tickets assigned to each it member accurately', function () {
    $it1 = User::factory()->itStaff()->create(['name' => 'Alice Tech']);
    $it2 = User::factory()->itStaff()->create(['name' => 'Bob Tech']);
    $employee = User::factory()->employee()->create();

    $monthDate = Carbon::parse('2026-10-10 12:00:00');

    // 2 tickets assigned to Alice (1 reopened)
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $it1->id,
        'status' => TicketStatus::InProgress,
        'created_at' => $monthDate,
        'first_responded_at' => $monthDate->copy()->addMinutes(20),
        'reopened_count' => 1,
    ]);
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $it1->id,
        'status' => TicketStatus::Open,
        'created_at' => $monthDate,
    ]);

    // 1 ticket assigned to Bob
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $it2->id,
        'status' => TicketStatus::Resolved,
        'created_at' => $monthDate,
        'resolved_at' => $monthDate->copy()->addHours(2),
    ]);

    // 1 unassigned ticket
    Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => null,
        'status' => TicketStatus::Open,
        'created_at' => $monthDate,
    ]);

    $component = Livewire::actingAs($it1)
        ->test('pages::analytics.index', ['month' => '2026-10']);

    $workloads = $component->instance()->itMemberWorkload->keyBy(fn ($item) => $item['user']->id);

    expect($workloads->get($it1->id)['assigned'])->toBe(2)
        ->and($workloads->get($it1->id)['reopened_count'])->toBe(1)
        ->and($workloads->get($it2->id)['assigned'])->toBe(1);

    $unassigned = $component->instance()->unassignedStats;
    expect($unassigned['month_unassigned'])->toBe(1);
});
