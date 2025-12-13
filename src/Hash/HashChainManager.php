<?php

namespace Corecave\Zatca\Hash;

use Corecave\Zatca\Models\ZatcaInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HashChainManager
{
    /**
     * The initial hash value for the first invoice.
     * This is base64 of SHA-256 hash of "0".
     */
    private const INITIAL_HASH = 'NWZlY2ViNjZmZmMzNmY3Y2QzOTAzNmQ5MmUyOWZjNjBlOTA4ODNlMTNkNjUwNWQ2Njc0N2ZhZDc2YzM4OWQ1MA==';

    /**
     * Get the next Invoice Counter Value (ICV).
     * This must be atomic and sequential.
     */
    public function getNextIcv(): int
    {
        return DB::transaction(function () {
            // PostgreSQL doesn't support FOR UPDATE with aggregate functions.
            // Lock the table to ensure atomicity, then get max ICV.
            $driver = DB::connection()->getDriverName();

            if ($driver === 'pgsql') {
                // Use advisory lock for PostgreSQL
                DB::select('SELECT pg_advisory_xact_lock(1)');
            }

            $maxIcv = ZatcaInvoice::max('icv');

            return ($maxIcv ?? 0) + 1;
        });
    }

    /**
     * Get the Previous Invoice Hash (PIH).
     * Returns the hash of the most recently generated invoice.
     */
    public function getPreviousInvoiceHash(): string
    {
        $lastInvoice = ZatcaInvoice::orderBy('icv', 'desc')->first();

        if (! $lastInvoice || empty($lastInvoice->hash)) {
            return $this->getInitialHash();
        }

        return $lastInvoice->hash;
    }

    /**
     * Get the initial hash for the first invoice.
     */
    public function getInitialHash(): string
    {
        // SHA-256 hash of "0" encoded in base64
        return base64_encode(hash('sha256', '0', true));
    }

    /**
     * Generate a new UUID.
     */
    public function generateUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Record an invoice in the hash chain.
     */
    public function recordInvoice(
        string $uuid,
        int $icv,
        string $invoiceNumber,
        string $hash,
        string $previousHash,
        string $type,
        string $subType,
        float $totalAmount,
        float $vatAmount,
        ?string $xml = null,
        ?string $signedXml = null,
        ?string $qrCode = null,
        ?string $referenceId = null
    ): ZatcaInvoice {
        return ZatcaInvoice::create([
            'uuid' => $uuid,
            'icv' => $icv,
            'invoice_number' => $invoiceNumber,
            'type' => $type,
            'subtype' => $subType,
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'status' => 'pending',
            'xml' => $xml,
            'signed_xml' => $signedXml,
            'qr_code' => $qrCode,
            'reference_id' => $referenceId,
            'total_amount' => $totalAmount,
            'vat_amount' => $vatAmount,
        ]);
    }

    /**
     * Update invoice status after ZATCA response.
     */
    public function updateInvoiceStatus(
        string $uuid,
        string $status,
        ?array $zatcaResponse = null,
        ?string $signedXml = null,
        ?string $qrCode = null
    ): bool {
        $invoice = ZatcaInvoice::where('uuid', $uuid)->first();

        if (! $invoice) {
            return false;
        }

        $invoice->status = $status;

        if ($zatcaResponse !== null) {
            $invoice->zatca_response = $zatcaResponse;
        }

        if ($signedXml !== null) {
            $invoice->signed_xml = $signedXml;
        }

        if ($qrCode !== null) {
            $invoice->qr_code = $qrCode;
        }

        return $invoice->save();
    }

    /**
     * Get an invoice by UUID.
     */
    public function getInvoiceByUuid(string $uuid): ?ZatcaInvoice
    {
        return ZatcaInvoice::where('uuid', $uuid)->first();
    }

    /**
     * Get an invoice by invoice number.
     */
    public function getInvoiceByNumber(string $invoiceNumber): ?ZatcaInvoice
    {
        return ZatcaInvoice::where('invoice_number', $invoiceNumber)->first();
    }

    /**
     * Verify the hash chain integrity.
     */
    public function verifyChain(?int $fromIcv = null, ?int $toIcv = null): array
    {
        $query = ZatcaInvoice::orderBy('icv');

        if ($fromIcv !== null) {
            $query->where('icv', '>=', $fromIcv);
        }

        if ($toIcv !== null) {
            $query->where('icv', '<=', $toIcv);
        }

        $invoices = $query->get();
        $errors = [];
        $previousHash = $this->getInitialHash();

        foreach ($invoices as $invoice) {
            // Check if previous hash matches
            if ($invoice->previous_hash !== $previousHash) {
                $errors[] = [
                    'icv' => $invoice->icv,
                    'uuid' => $invoice->uuid,
                    'error' => 'Previous hash mismatch',
                    'expected' => $previousHash,
                    'actual' => $invoice->previous_hash,
                ];
            }

            $previousHash = $invoice->hash;
        }

        return [
            'valid' => empty($errors),
            'checked' => $invoices->count(),
            'errors' => $errors,
        ];
    }

    /**
     * Get hash chain statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total_invoices' => ZatcaInvoice::count(),
            'pending' => ZatcaInvoice::where('status', 'pending')->count(),
            'reported' => ZatcaInvoice::where('status', 'reported')->count(),
            'cleared' => ZatcaInvoice::where('status', 'cleared')->count(),
            'rejected' => ZatcaInvoice::where('status', 'rejected')->count(),
            'last_icv' => ZatcaInvoice::max('icv') ?? 0,
            'last_invoice_date' => ZatcaInvoice::max('created_at'),
        ];
    }

    /**
     * Reserve an ICV (useful for async operations).
     * Returns the reserved ICV that can be used later.
     */
    public function reserveIcv(): int
    {
        return $this->getNextIcv();
    }

    /**
     * Check if an ICV is already used.
     */
    public function isIcvUsed(int $icv): bool
    {
        return ZatcaInvoice::where('icv', $icv)->exists();
    }

    /**
     * Get invoices in a date range.
     */
    public function getInvoicesInRange(string $startDate, string $endDate): \Illuminate\Database\Eloquent\Collection
    {
        return ZatcaInvoice::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('icv')
            ->get();
    }
}
