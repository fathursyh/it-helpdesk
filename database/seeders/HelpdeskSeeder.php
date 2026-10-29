<?php

namespace Database\Seeders;

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class HelpdeskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Hash::make('password');

        // IT Staff Members
        $itLead = User::updateOrCreate(
            ['email' => 'iqbal@company.com'],
            [
                'name' => 'Iqbal Zulfikar',
                'password' => $password,
                'role' => UserRole::ITStaff,
                'division' => Division::It,
                'email_verified_at' => now(),
            ]
        );

        $itSupport = User::updateOrCreate(
            ['email' => 'fathur@company.com'],
            [
                'name' => 'Fathursyah Awandanu',
                'password' => $password,
                'role' => UserRole::ITStaff,
                'division' => Division::It,
                'email_verified_at' => now(),
            ]
        );

        // Department Employees
        $hrUser = User::updateOrCreate(
            ['email' => 'hr@company.com'],
            [
                'name' => 'Emma Davis',
                'password' => $password,
                'role' => UserRole::Employee,
                'division' => Division::Hr,
                'email_verified_at' => now(),
            ]
        );

        $financeUser = User::updateOrCreate(
            ['email' => 'admin@company.com'],
            [
                'name' => 'David Miller',
                'password' => $password,
                'role' => UserRole::Employee,
                'division' => Division::Admin,
                'email_verified_at' => now(),
            ]
        );

        $opsUser = User::updateOrCreate(
            ['email' => 'ops@company.com'],
            [
                'name' => 'Rachel Green',
                'password' => $password,
                'role' => UserRole::Employee,
                'division' => Division::Operations,
                'email_verified_at' => now(),
            ]
        );

        $bdUser = User::updateOrCreate(
            ['email' => 'bd@company.com'],
            [
                'name' => 'Harvey Specter',
                'password' => $password,
                'role' => UserRole::Employee,
                'division' => Division::Bd,
                'email_verified_at' => now(),
            ]
        );

        $t1 = Ticket::create([
            'ticket_number' => 'IT-2610-0001',
            'user_id' => $opsUser->id,
            'assigned_to_id' => $itLead->id,
            'division' => Division::Operations,
            'category' => TicketCategory::Network,
            'priority' => TicketPriority::Urgent,
            'status' => TicketStatus::InProgress,
            'title' => 'Warehouse Wi-Fi connection dropping repeatedly',
            'description' => 'The barcode scanners in zone B keep losing network connection every 5-10 minutes. This is halting our dispatch operations. We have tried rebooting the access point on pillar 4 but the problem persists.',
            'created_at' => now()->subHours(4),
        ]);

        TicketMessage::create([
            'ticket_id' => $t1->id,
            'user_id' => $itLead->id,
            'content' => 'Checked the Cisco switch logs for zone B. It looks like port 14 has excessive packet drops. I will walk over to check the patch cable.',
            'is_internal' => true,
            'created_at' => now()->subHours(3),
        ]);

        TicketMessage::create([
            'ticket_id' => $t1->id,
            'user_id' => $itLead->id,
            'content' => 'Hi Rachel, we are investigating the network switch port connected to pillar 4. We will test a backup line within the next 30 minutes.',
            'is_internal' => false,
            'created_at' => now()->subHours(2)->subMinutes(45),
        ]);

        TicketMessage::create([
            'ticket_id' => $t1->id,
            'user_id' => $opsUser->id,
            'content' => 'Thanks Alex! Let us know when the backup line is ready so we can run a test scan.',
            'is_internal' => false,
            'created_at' => now()->subHours(2),
        ]);

        // Ticket 2: Hardware Issue (Open, High Priority)
        Ticket::create([
            'ticket_number' => 'IT-2610-0002',
            'user_id' => $hrUser->id,
            'assigned_to_id' => $itSupport->id,
            'division' => Division::Hr,
            'category' => TicketCategory::Hardware,
            'priority' => TicketPriority::High,
            'status' => TicketStatus::Open,
            'title' => 'HR Printer jamming and displaying error code 50.4',
            'description' => 'The HP LaserJet in the HR filing room is making a grinding noise and refusing to feed paper from Tray 2. Need this for onboarding printouts today.',
            'created_at' => now()->subHours(2),
        ]);

        // Ticket 3: Software / Access (Resolved)
        $t3 = Ticket::create([
            'ticket_number' => 'IT-2610-0003',
            'user_id' => $bdUser->id,
            'assigned_to_id' => $itLead->id,
            'division' => Division::Bd,
            'category' => TicketCategory::AccountAccess,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Resolved,
            'title' => 'Need access permissions for CRM Sales Pipeline Q4 folder',
            'description' => 'I cannot open the shared drive folder for BD team proposals. It says Access Denied.',
            'resolved_at' => now()->subHour(),
            'created_at' => now()->subDays(1),
        ]);

        TicketMessage::create([
            'ticket_id' => $t3->id,
            'user_id' => $itLead->id,
            'content' => 'Permissions granted for BD-Sales Security Group in Active Directory. Please restart your file explorer or log out and back in.',
            'is_internal' => false,
            'created_at' => now()->subHour(),
        ]);

        // Ticket 4: Software Issue (Closed)
        Ticket::create([
            'ticket_number' => 'IT-2610-0004',
            'user_id' => $financeUser->id,
            'assigned_to_id' => $itSupport->id,
            'division' => Division::Admin,
            'category' => TicketCategory::Software,
            'priority' => TicketPriority::Low,
            'status' => TicketStatus::Closed,
            'title' => 'Update Adobe Acrobat Pro license on workstation',
            'description' => 'License expired notification appeared on screen.',
            'resolved_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2),
            'created_at' => now()->subDays(3),
        ]);
    }
}
