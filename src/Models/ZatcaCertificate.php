<?php

namespace Corecave\Zatca\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $device_id
 * @property string $type
 * @property string $certificate
 * @property string $private_key
 * @property string $secret
 * @property string|null $request_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ZatcaCertificate extends Model
{
    use UsesZatcaConnection;

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
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
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
     * @var list<string>
     */
    protected $hidden = [
        'private_key',
        'secret',
    ];

    /**
     * Scope for active certificates.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for certificate type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for compliance certificates.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompliance(Builder $query): Builder
    {
        return $query->where('type', 'compliance');
    }

    /**
     * Scope for production certificates.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProduction(Builder $query): Builder
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

        // Carbon 3 counts signed days from now to the expiry, so one already expired counts as expiring
        return now()->diffInDays($this->expires_at) <= $days;
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
