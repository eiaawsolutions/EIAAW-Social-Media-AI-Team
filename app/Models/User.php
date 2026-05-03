<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Mass-assignable attributes.
     *
     * `is_super_admin` is INTENTIONALLY excluded — it must only be set by
     * console commands (PromoteSuperAdmin, etc.) via forceFill / direct
     * assignment, never by a web request. Same logic for two-factor secrets:
     * those are written by the 2FA flow, not by user-supplied form data.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_workspace_id',
        'last_login_at',
        'avatar_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',

            // TOTP shared secret + recovery codes are bearer credentials —
            // anyone holding the secret can mint a valid 2FA code forever.
            // Encrypt at rest with APP_KEY so a DB leak alone doesn't burn
            // every super-admin's 2FA. The text-column types in the migration
            // are already large enough to fit the ciphertext.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function membership(Workspace $workspace): ?WorkspaceMember
    {
        return WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $this->id)
            ->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_super_admin,
            'agency' => $this->workspaces()->exists() || $this->is_super_admin,
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  MFA — Filament app-authentication (TOTP)
     |
     |  Wired in AgencyPanelProvider::panel() via ->multiFactorAuthentication()
     |  with isRequired keyed off is_super_admin. Every EIAAW staff account
     |  is forced through TOTP set-up on first login; tenant operators can
     |  still opt in but aren't required.
     |
     |  The secret + recovery codes are encrypted at rest via the casts
     |  above, so these accessors return / accept plaintext as the contract
     |  requires while the DB only ever sees ciphertext.
     ───────────────────────────────────────────────────────────────── */

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        // forceFill bypasses $fillable — `two_factor_secret` is intentionally
        // excluded from mass-assign (see $fillable comment), so this is the
        // correct write path.
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $secret === null ? null : ($this->two_factor_confirmed_at ?? now()),
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        // Shows up in the user's authenticator app entry — pair it with the
        // brand so the user can tell which EIAAW account a given 6-digit
        // code belongs to when they have several.
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
    }
}
