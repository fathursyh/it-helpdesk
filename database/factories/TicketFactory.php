<?php

namespace Database\Factories;

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'assigned_to_id' => null,
            'division' => fake()->randomElement(Division::cases()),
            'category' => fake()->randomElement(TicketCategory::cases()),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'status' => TicketStatus::Open,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraphs(2, true),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::Open,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::InProgress,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => [
            'priority' => TicketPriority::Urgent,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'assigned_to_id' => $user->id,
        ]);
    }
}
