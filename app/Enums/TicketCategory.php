<?php

namespace App\Enums;

enum TicketCategory: string
{
    case Hardware = 'hardware';
    case Software = 'software';
    case Network = 'network';
    case AccountAccess = 'account_access';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hardware => 'Hardware',
            self::Software => 'Software / Application',
            self::Network => 'Network / Connectivity',
            self::AccountAccess => 'Account & Access',
            self::Other => 'Other',
        };
    }
}
