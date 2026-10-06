<?php

namespace Corecave\Zatca\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $icv
 * @property string $invoice_number
 * @property string $type
 * @property string $subtype
 * @property string $hash
 * @property string $previous_hash
 * @property string $status
 * @property string|null $xml
 * @property string|null $signed_xml
 * @property string|null $qr_code
 * @property array<string, mixed>|null $zatca_response
 * @property string|null $reference_id
 * @property float $total_amount
 * @property float $vat_amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ZatcaInvoice extends Model
{
    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('zatca.tables.invoices', 'zatca_invoices');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'icv',
        'invoice_number',
        'type',
        'subtype',
        'hash',
        'previous_hash',
        'status',
        'xml',
        'signed_xml',
        'qr_code',
        'zatca_response',
        'reference_id',
        'total_amount',
        'vat_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'icv' => 'integer',
        'zatca_response' => 'array',
        'total_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    /**
     * Scope for status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for pending invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for reported invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReported(Builder $query): Builder
    {
        return $query->where('status', 'reported');
    }

    /**
     * Scope for cleared invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCleared(Builder $query): Builder
    {
        return $query->where('status', 'cleared');
    }

    /**
     * Scope for rejected invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope for invoice type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for standard invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStandard(Builder $query): Builder
    {
        return $query->where('type', 'standard');
    }

    /**
     * Scope for simplified invoices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSimplified(Builder $query): Builder
    {
        return $query->where('type', 'simplified');
    }

    /**
     * Check if the invoice was successfully processed.
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, ['reported', 'cleared']);
    }

    /**
     * Check if this is a standard (B2B) invoice.
     */
    public function isStandard(): bool
    {
        return str_starts_with($this->subtype, '01');
    }

    /**
     * Check if this is a simplified (B2C) invoice.
     */
    public function isSimplified(): bool
    {
        return str_starts_with($this->subtype, '02');
    }

    /**
     * Get the original invoice (for credit/debit notes).
     */
    public function originalInvoice(): ?self
    {
        if ($this->reference_id === null) {
            return null;
        }

        return self::where('invoice_number', $this->reference_id)->first();
    }

    /**
     * Get related credit/debit notes.
     *
     * @return Collection<int, self>
     */
    public function relatedNotes(): Collection
    {
        return self::where('reference_id', $this->invoice_number)->get();
    }

    /**
     * Get validation errors from ZATCA response.
     *
     * @return array<array-key, mixed>
     */
    public function getValidationErrors(): array
    {
        return $this->zatca_response['validationResults']['errorMessages'] ?? [];
    }

    /**
     * Get validation warnings from ZATCA response.
     *
     * @return array<array-key, mixed>
     */
    public function getValidationWarnings(): array
    {
        return $this->zatca_response['validationResults']['warningMessages'] ?? [];
    }
}
