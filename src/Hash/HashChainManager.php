<?php

namespace Corecave\Zatca\Hash;

use Corecave\Zatca\Exceptions\InvoiceException;
use Corecave\Zatca\Models\ZatcaInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A device's invoice counter and hash chain. The manager the container holds belongs to no device:
 * `forDevice()` gives the chain of the device that signs, and reading or writing a chain before that is
 * refused.
 */
class HashChainManager
{
    private ?string $deviceId = null;

    public function __construct(private readonly HashChainLock $lock = new HashChainLock) {}

    /**
     * The chain of one device: its counter, its hashes and its lock.
     */
    public function forDevice(string $deviceId): static
    {
        $chain = clone $this;
        $chain->deviceId = $deviceId;

        return $chain;
    }

    /**
     * The device's next Invoice Counter Value (ICV), under the device's lock. The lock is held until the
     * caller's transaction ends, so that transaction must also record the invoice: no other issue of the
     * device can take the same counter or read the hash before it.
     *
     * @throws InvoiceException when no device is chosen or no transaction is open
     */
    public function getNextIcv(): int
    {
        $this->lock->acquire(DB::connection(), $this->device());

        return (int) $this->invoices()->max('icv') + 1;
    }

    /**
     * The device's Previous Invoice Hash (PIH): the hash of its invoice with the highest counter, or the
     * initial hash before its first.
     *
     * @throws InvoiceException when no device is chosen
     */
    public function getPreviousInvoiceHash(): string
    {
        $hash = $this->invoices()->orderByDesc('icv')->value('hash');

        return is_string($hash) && $hash !== '' ? $hash : $this->getInitialHash();
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
     * Record an invoice in the device's hash chain.
     *
     * @throws InvoiceException when no device is chosen
     */
    public function recordInvoice(InvoiceRecord $record): ZatcaInvoice
    {
        return ZatcaInvoice::create([
            'device_id' => $this->device(),
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
     * Verify the device's hash chain.
     *
     * @return array{valid: bool, checked: int, errors: list<array{icv: int, uuid: string, error: string, expected: string, actual: string}>}
     */
    public function verifyChain(?int $fromIcv = null, ?int $toIcv = null): array
    {
        $errors = [];
        $checked = 0;
        $previousHash = $this->hashBefore($fromIcv);

        // Read by counter in bounded chunks, so a long chain never sits in memory whole
        foreach ($this->invoicesBetween($fromIcv, $toIcv)->lazyById(500, 'icv') as $invoice) {
            $checked++;

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
            'valid' => $errors === [],
            'checked' => $checked,
            'errors' => $errors,
        ];
    }

    /**
     * The hash the first invoice of a range must name: the hash of the highest counter below it, or the
     * initial hash when the range starts the chain or the invoice below it has no hash.
     */
    protected function hashBefore(?int $fromIcv): string
    {
        if ($fromIcv === null) {
            return $this->getInitialHash();
        }

        $hash = $this->invoices()->where('icv', '<', $fromIcv)->orderByDesc('icv')->value('hash');

        return $hash ?? $this->getInitialHash();
    }

    /**
     * The invoices whose counters fall in the range, either end left open when not given.
     *
     * @return Builder<ZatcaInvoice>
     */
    protected function invoicesBetween(?int $fromIcv, ?int $toIcv): Builder
    {
        return $this->invoices()
            ->when($fromIcv !== null, fn (Builder $query) => $query->where('icv', '>=', $fromIcv))
            ->when($toIcv !== null, fn (Builder $query) => $query->where('icv', '<=', $toIcv));
    }

    /**
     * The device's chain statistics.
     *
     * @return array{total_invoices: int, pending: int, reported: int, cleared: int, rejected: int, last_icv: mixed, last_invoice_date: mixed}
     */
    public function getStatistics(): array
    {
        return [
            'total_invoices' => $this->invoices()->count(),
            'pending' => $this->invoices()->where('status', 'pending')->count(),
            'reported' => $this->invoices()->where('status', 'reported')->count(),
            'cleared' => $this->invoices()->where('status', 'cleared')->count(),
            'rejected' => $this->invoices()->where('status', 'rejected')->count(),
            'last_icv' => $this->invoices()->max('icv') ?? 0,
            'last_invoice_date' => $this->invoices()->max('created_at'),
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
     * Whether the device has used the counter.
     */
    public function isIcvUsed(int $icv): bool
    {
        return $this->invoices()->where('icv', $icv)->exists();
    }

    /**
     * The device's invoices.
     *
     * @return Builder<ZatcaInvoice>
     *
     * @throws InvoiceException when no device is chosen
     */
    protected function invoices(): Builder
    {
        return ZatcaInvoice::query()->where('device_id', $this->device());
    }

    /**
     * @throws InvoiceException when no device is chosen
     */
    protected function device(): string
    {
        return $this->deviceId ?? throw InvoiceException::noDevice();
    }
}
