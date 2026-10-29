<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'blue',
            self::High => 'orange',
            self::Urgent => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Low => 'bars-2',
            self::Medium => 'bars-3',
            self::High => 'chevron-double-up',
            self::Urgent => 'exclamation-triangle',
        };
    }
}
