<?php

namespace App;

enum OrderStatus: string
{
    case Pending = 'pending';
    case InProgress = 'processing';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::ReadyForPickup => 'Ready for Pickup',
            self::Completed => 'Completed',
        };
    }

    /**
     * The color tone used by the status badge.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::InProgress => 'progress',
            self::ReadyForPickup => 'info',
            self::Completed => 'success',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::InProgress,
            self::InProgress => self::ReadyForPickup,
            self::ReadyForPickup => self::Completed,
            self::Completed => null,
        };
    }

    public static function toneFor(string $status): string
    {
        return self::tryFrom($status)?->tone() ?? 'neutral';
    }

    public static function labelFor(string $status): string
    {
        return self::tryFrom($status)?->label()
            ?? str($status)->replace('_', ' ')->title()->toString();
    }
}
