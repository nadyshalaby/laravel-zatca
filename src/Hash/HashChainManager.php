<?php

namespace Corecave\Zatca\Hash;

use Corecave\Zatca\Models\ZatcaInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HashChainManager
{
    public function __construct(private readonly HashChainLock $lock = new HashChainLock) {}

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
                // One advisory lock per workshop schema, so workshops never wait for each other
                $this->lock->acquire(DB::connection());
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
    public function recordInvoice(InvoiceRecord $record): ZatcaInvoice
    {
        return ZatcaInvoice::create([
            'uuid' => $record->uuid,
            'icv' => $record->icv,
            'invoice_number' => $record->invoiceNumber,
            'type' => $record->type,
            'subtype' => $record->subType,
            'hash' => $record->hash,
            'previous_hash' => $record->previousHash,
            'status' => 'pending',
            'xml' => $record->xml,
            'signed_xml' => $record->signedXml,
            'qr_code' => $record->qrCode,
            'reference_id' => $record->referenceId,
            'total_amount' => $record->totalAmount,
            'vat_amount' => $record->vatAmount,
        ]);
    }

    /**
     * Update invoice status after ZATCA response.
     */
    public function updateInvoiceStatus(string $uuid, string $status, StatusUpdate $update = new StatusUpdate): bool
    {
        $invoice = ZatcaInvoice::where('uuid', $uuid)->first();

        if (! $invoice) {
            return false;
        }

        $invoice->status = $status;

        if ($update->zatcaResponse !== null) {
            $invoice->zatca_response = $update->zatcaResponse;
        }

        if ($update->signedXml !== null) {
            $invoice->signed_xml = $update->signedXml;
        }

        if ($update->qrCode !== null) {
            $invoice->qr_code = $update->qrCode;
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
    public function getInvoicesInRange(string $startDate, string $endDate): Collection
    {
        return ZatcaInvoice::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('icv')
            ->get();
    }
}
