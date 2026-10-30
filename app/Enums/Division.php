<?php

namespace App\Enums;

enum Division: string
{
    case Hr = 'hr';
    case Admin = 'admin';
    case Operations = 'operations';
    case Bd = 'bd';
    case Executive = 'executive';
    case Field = 'field';
    case It = 'it';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'HR',
            self::Admin => 'Admin',
            self::Operations => 'Operations',
            self::Bd => 'BD',
            self::Executive => 'Executive',
            self::Field => 'Field',
            self::It => 'IT',
            self::Other => 'Other',
        };
    }

    /**
     * Divisions available for public employee registration (excludes internal IT).
     *
     * @return array<int, self>
     */
    public static function publicCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $division): bool => $division !== self::It,
        ));
    }
}