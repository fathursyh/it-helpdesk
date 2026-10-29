<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'sky',
            self::InProgress => 'amber',
            self::Resolved => 'emerald',
            self::Closed => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'inbox-stack',
            self::InProgress => 'arrow-path',
            self::Resolved => 'check-circle',
            self::Closed => 'archive-box',
        };
    }
}
