<?php

namespace App\Filament\Agency\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Thin Dashboard subclass so the Agency sidebar position is deterministic.
 *
 * The stock Filament\Pages\Dashboard ships with navigationSort = -2, which
 * tied with "Platform setup" (also -2) and left their relative order to an
 * unstable tiebreaker. Pinning an explicit sort here fixes the intended order:
 * Platform setup (-3) -> Dashboard (-2) -> Setup wizard (-1).
 */
class Dashboard extends BaseDashboard
{
    protected static ?int $navigationSort = -2;
}
