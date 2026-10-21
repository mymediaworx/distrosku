<?php

namespace App\Enums;

enum TaxClass: string
{
    case CigaretteStamped = 'cigarette_stamped';
    case Cigar = 'cigar';
    case Pipe = 'pipe';
    case SnuffChew = 'snuff_chew';
    case Vapor = 'vapor';
    case Ryo = 'ryo';
    case Hemp = 'hemp';
    case Kratom = 'kratom';
    case Beverage = 'beverage';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::CigaretteStamped => 'Cigarette (stamped)',
            self::SnuffChew => 'Snuff / Chew',
            self::Ryo => 'Roll-your-own',
            default => ucfirst($this->value),
        };
    }

    /** PACT Act covered by default (cigarettes, RYO, smokeless, vapor). */
    public function defaultPact(): bool
    {
        return in_array($this, [self::CigaretteStamped, self::Ryo, self::SnuffChew, self::Vapor], true);
    }

    /** 0.15: regulated (age-restricted) = tobacco, vapor, hemp, kratom; everything except general and beverage. */
    public function isRegulated(): bool
    {
        return ! in_array($this, [self::General, self::Beverage], true);
    }

    public static function options(): array
    {
        $o = [];
        foreach (self::cases() as $c) {
            $o[$c->value] = $c->label();
        }

        return $o;
    }
}
