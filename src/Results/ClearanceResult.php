<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

class ClearanceResult
{
    protected InvoiceInterface $invoice;

    protected string $clearedXml;

    protected ?string $qrCode;

    protected array $response;

    public function __construct(
        InvoiceInterface $invoice,
        string $clearedXml,
        ?string $qrCode,
        array $response
    ) {
        $this->invoice = $invoice;
        $this->clearedXml = $clearedXml;
        $this->qrCode = $qrCode;
        $this->response = $response;
    }

    /**
     * Check if the clearance was successful.
     */
    public function isSuccess(): bool
    {
        $status = $this->response['clearanceStatus'] ?? $this->response['status'] ?? '';

        return in_array(strtoupper($status), ['CLEARED', 'SUCCESS', 'PASS']);
    }

    /**
     * Get the invoice.
     */
    public function getInvoice(): InvoiceInterface
    {
        return $this->invoice;
    }

    /**
     * Get the cleared XML (with ZATCA stamp).
     */
    public function getClearedXml(): string
    {
        return $this->clearedXml;
    }

    /**
     * Get the QR code (added by ZATCA).
     */
    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    /**
     * Get the ZATCA response.
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->response['validationResults']['errorMessages'] ?? [];
    }

    /**
     * Get validation warnings.
     */
    public function getWarnings(): array
    {
        return $this->response['validationResults']['warningMessages'] ?? [];
    }

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return count($this->getWarnings()) > 0;
    }

    /**
     * Get the clearance status.
     */
    public function getStatus(): string
    {
        return $this->response['clearanceStatus'] ?? $this->response['status'] ?? 'UNKNOWN';
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess(),
            'status' => $this->getStatus(),
            'uuid' => $this->invoice->getUuid(),
            'invoice_number' => $this->invoice->getInvoiceNumber(),
            'qr_code' => $this->qrCode,
            'errors' => $this->getErrors(),
            'warnings' => $this->getWarnings(),
        ];
    }
}
