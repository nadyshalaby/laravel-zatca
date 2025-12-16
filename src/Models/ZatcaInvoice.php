<?php

namespace Corecave\Zatca\Models;

use Carbon\Carbon;
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
 * @property array|null $zatca_response
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
     * @var array<string>
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
        'invoiceable_type',
        'invoiceable_id',
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
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for pending invoices.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for reported invoices.
     */
    public function scopeReported($query)
    {
        return $query->where('status', 'reported');
    }

    /**
     * Scope for cleared invoices.
     */
    public function scopeCleared($query)
    {
        return $query->where('status', 'cleared');
    }

    /**
     * Scope for rejected invoices.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope for invoice type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for standard invoices.
     */
    public function scopeStandard($query)
    {
        return $query->where('type', 'standard');
    }

    /**
     * Scope for simplified invoices.
     */
    public function scopeSimplified($query)
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
    public function originalInvoice()
    {
        if ($this->reference_id === null) {
            return null;
        }

        return self::where('invoice_number', $this->reference_id)->first();
    }

    /**
     * Get related credit/debit notes.
     */
    public function relatedNotes()
    {
        return self::where('reference_id', $this->invoice_number)->get();
    }

    /**
     * Get validation errors from ZATCA response.
     */
    public function getValidationErrors(): array
    {
        return $this->zatca_response['validationResults']['errorMessages'] ?? [];
    }

    /**
     * Get validation warnings from ZATCA response.
     */
    public function getValidationWarnings(): array
    {
        return $this->zatca_response['validationResults']['warningMessages'] ?? [];
    }

    /**
     * Get the parent invoiceable model (Order or Booking).
     */
    public function invoiceable()
    {
        return $this->morphTo();
    }

    /**
     * Scope for invoices belonging to a specific model type.
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('invoiceable_type', $modelClass);
    }

    /**
     * Scope for invoices belonging to a specific entity.
     */
    public function scopeForEntity($query, $entity)
    {
        return $query->where('invoiceable_type', get_class($entity))
            ->where('invoiceable_id', $entity->id);
    }

    /**
     * Get QR code as PNG image (base64 encoded).
     *
     * Generates a PNG image from the TLV QR code data using SimpleSoftwareIO/QrCode.
     */
    public function getQrCodeImageAttribute(): ?string
    {
        if (empty($this->qr_code)) {
            return null;
        }

        try {
            // Generate QR code as base64 PNG using SimpleSoftwareIO/QrCode
            $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')
                ->size(200)
                ->margin(1)
                ->generate($this->qr_code);

            return base64_encode($qrCode);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get QR code as SVG string.
     */
    public function getQrCodeSvgAttribute(): ?string
    {
        if (empty($this->qr_code)) {
            return null;
        }

        try {
            return \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(200)
                ->margin(1)
                ->generate($this->qr_code);
        } catch (\Exception $e) {
            return null;
        }
    }
}
