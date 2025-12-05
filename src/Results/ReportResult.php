<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

class ReportResult
{
    protected InvoiceInterface $invoice;

    protected string $signedXml;

    protected string $qrCode;

    protected array $response;

    public function __construct(
        InvoiceInterface $invoice,
        string $signedXml,
        string $qrCode,
        array $response
    ) {
        $this->invoice = $invoice;
        $this->signedXml = $signedXml;
        $this->qrCode = $qrCode;
        $this->response = $response;
    }

    /**
     * Check if the report was successful.
     */
    public function isSuccess(): bool
    {
        $status = $this->response['reportingStatus'] ?? $this->response['status'] ?? '';

        return in_array(strtoupper($status), ['REPORTED', 'SUCCESS', 'PASS']);
    }

    /**
     * Get the invoice.
     */
    public function getInvoice(): InvoiceInterface
    {
        return $this->invoice;
    }

    /**
     * Get the signed XML.
     */
    public function getSignedXml(): string
    {
        return $this->signedXml;
    }

    /**
     * Get the QR code.
     */
    public function getQrCode(): string
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
     * Get the reporting status.
     */
    public function getStatus(): string
    {
        return $this->response['reportingStatus'] ?? $this->response['status'] ?? 'UNKNOWN';
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
