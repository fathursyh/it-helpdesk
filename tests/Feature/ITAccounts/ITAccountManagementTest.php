<?php

use App\Enums\Division;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('guests are redirected to login when visiting it-accounts', function () {
    $this->get(route('it-accounts.index'))
        ->assertRedirect(route('login'));
});

test('regular employees cannot access it-accounts page', function () {
    $employee = User::factory()->employee(Division::Hr)->create();

    $this->actingAs($employee)
        ->get(route('it-accounts.index'))
        ->assertForbidden();
});

test('it staff can view it-accounts page and list of team members', function () {
    $itStaff1 = User::factory()->itStaff()->create(['name' => 'Alex IT']);
    $itStaff2 = User::factory()->itStaff()->create(['name' => 'Sam IT']);
    $employee = User::factory()->employee()->create(['name' => 'Regular Employee']);

    $this->actingAs($itStaff1)
        ->get(route('it-accounts.index'))
        ->assertOk();

    $test = Livewire::actingAs($itStaff1)
        ->test('pages::it-accounts.index')
        ->assertSee('Alex IT')
        ->assertSee('Sam IT');

    $itNames = collect($test->instance()->itStaffMembers->items())->pluck('name');
    expect($itNames)->toContain('Alex IT', 'Sam IT')
        ->and($itNames)->not->toContain('Regular Employee');
});

test('it staff can create a new it account', function () {
    $itAdmin = User::factory()->itStaff()->create();

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->set('newName', 'Jordan Tech')
        ->set('newEmail', 'jordan.tech@company.com')
        ->set('newPassword', 'password123')
        ->set('newPassword_confirmation', 'password123')
        ->set('newDivision', Division::It->value)
        ->call('createItAccount')
        ->assertHasNoErrors();

    $newItUser = User::where('email', 'jordan.tech@company.com')->first();

    expect($newItUser)->not->toBeNull()
        ->and($newItUser->name)->toBe('Jordan Tech')
        ->and($newItUser->role)->toBe(UserRole::ITStaff)
        ->and($newItUser->division)->toBe(Division::It)
        ->and(Hash::check('password123', $newItUser->password))->toBeTrue();
});

test('creating it account validates unique email and required fields', function () {
    $itAdmin = User::factory()->itStaff()->create(['email' => 'existing@company.com']);

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->set('newName', '')
        ->set('newEmail', 'existing@company.com')
        ->set('newPassword', 'pass')
        ->set('newPassword_confirmation', 'mismatch')
        ->call('createItAccount')
        ->assertHasErrors(['newName', 'newEmail', 'newPassword']);
});

test('it staff can edit another it staff member details and password', function () {
    $itAdmin = User::factory()->itStaff()->create();
    $itMember = User::factory()->itStaff()->create([
        'name' => 'Old Name',
        'email' => 'old.email@company.com',
    ]);

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->call('openEditModal', $itMember->id)
        ->set('editName', 'Updated Name')
        ->set('editEmail', 'updated.email@company.com')
        ->set('editDivision', Division::Operations->value)
        ->set('editPassword', 'newpassword99')
        ->call('saveEditedItAccount')
        ->assertHasNoErrors();

    $itMember->refresh();

    expect($itMember->name)->toBe('Updated Name')
        ->and($itMember->email)->toBe('updated.email@company.com')
        ->and($itMember->division)->toBe(Division::Operations)
        ->and(Hash::check('newpassword99', $itMember->password))->toBeTrue();
});

test('it staff can promote an employee to it staff', function () {
    $itAdmin = User::factory()->itStaff()->create();
    $employee = User::factory()->employee(Division::Operations)->create([
        'name' => 'Jane Employee',
    ]);

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->set('promoteEmployeeId', $employee->id)
        ->call('promoteEmployee')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->role)->toBe(UserRole::ITStaff)
        ->and($employee->division)->toBe(Division::It);
});

test('it staff can demote an it staff member to employee and unassigns open tickets', function () {
    $itAdmin = User::factory()->itStaff()->create();
    $itMember = User::factory()->itStaff()->create();

    $employee = User::factory()->employee()->create();
    $ticket = Ticket::factory()->create([
        'user_id' => $employee->id,
        'assigned_to_id' => $itMember->id,
        'status' => TicketStatus::InProgress,
    ]);

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->call('demoteItStaff', $itMember->id);

    $itMember->refresh();
    $ticket->refresh();

    expect($itMember->role)->toBe(UserRole::Employee)
        ->and($ticket->assigned_to_id)->toBeNull();
});

test('it staff cannot demote their own account', function () {
    $itAdmin = User::factory()->itStaff()->create();
    User::factory()->itStaff()->create(); // second IT member

    $this->actingAs($itAdmin);

    Livewire::test('pages::it-accounts.index')
        ->call('demoteItStaff', $itAdmin->id);

    $itAdmin->refresh();

    expect($itAdmin->role)->toBe(UserRole::ITStaff);
});

test('it staff cannot demote the last remaining it staff member', function () {
    $loneIt = User::factory()->itStaff()->create();

    $this->actingAs($loneIt);

    Livewire::test('pages::it-accounts.index')
        ->call('demoteItStaff', $loneIt->id);

    $loneIt->refresh();

    expect($loneIt->role)->toBe(UserRole::ITStaff);
});

test('public registration assigns employee role and public divisions exclude IT', function () {
    expect(Division::publicCases())
        ->not->toContain(Division::It);

    $response = $this->post(route('register.store'), [
        'name' => 'Public User',
        'email' => 'public@company.com',
        'division' => Division::Hr->value,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'public@company.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Employee)
        ->and($user->division)->toBe(Division::Hr);
});
