<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Rider = 'rider';
    case Admin = 'admin';

    public function tokenAbility(): string
    {
        return $this->value.':access';
    }
}
