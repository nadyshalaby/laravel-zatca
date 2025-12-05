<?php

namespace Corecave\Zatca\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $certificate
 * @property string $private_key
 * @property string $secret
 * @property string|null $request_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ZatcaCertificate extends Model
{
    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('zatca.tables.certificates', 'zatca_certificates');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'type',
        'certificate',
        'private_key',
        'secret',
        'request_id',
        'issued_at',
        'expires_at',
        'is_active',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'private_key',
        'secret',
    ];

    /**
     * Scope for active certificates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for certificate type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for compliance certificates.
     */
    public function scopeCompliance($query)
    {
        return $query->where('type', 'compliance');
    }

    /**
     * Scope for production certificates.
     */
    public function scopeProduction($query)
    {
        return $query->where('type', 'production');
    }

    /**
     * Check if the certificate is expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Check if the certificate is expiring soon.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->diffInDays(now()) <= $days;
    }

    /**
     * Get days until expiry.
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) now()->diffInDays($this->expires_at, false);
    }
}
