<?php

namespace App\Enums;

enum PlanIdEnum: string
{
    case Free = 'free';
    case Family = 'family';
    case Clinical = 'clinical';
    case Infinity = 'infinity';

    public function label(): string
    {
        return match ($this) {
            self::Infinity => 'Plano Infinity',
            self::Family => 'Legado Familiar',
            self::Clinical => 'Patrimonial & Eterno',
            self::Free => 'Gratuito',
        };
    }
}
