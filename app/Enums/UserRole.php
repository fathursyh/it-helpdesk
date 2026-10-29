<?php

namespace App\Enums;

enum UserRole: string
{
    case ITStaff = 'it_staff';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::ITStaff => 'IT Staff',
            self::Employee => 'Employee',
        };
    }
}
