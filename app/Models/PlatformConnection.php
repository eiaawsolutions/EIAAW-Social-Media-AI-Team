<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class PlatformConnection extends Model
{
    protected $fillable = [
        'brand_id', 'platform', 'platform_account_id', 'display_handle',
        'access_token_encrypted', 'refresh_token_encrypted', 'token_expires_at',
        'scopes', 'status', 'blotato_account_id',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function setAccessToken(string $token): void
    {
        $this->access_token_encrypted = Crypt::encryptString($token);
    }

    public function getAccessToken(): ?string
    {
        return $this->access_token_encrypted ? Crypt::decryptString($this->access_token_encrypted) : null;
    }

    public function setRefreshToken(?string $token): void
    {
        $this->refresh_token_encrypted = $token ? Crypt::encryptString($token) : null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refresh_token_encrypted ? Crypt::decryptString($this->refresh_token_encrypted) : null;
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
