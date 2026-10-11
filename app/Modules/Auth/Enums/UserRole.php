<?php

namespace App\Modules\Auth\Enums;

enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';
}
