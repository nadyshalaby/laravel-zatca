<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

class ClearanceResult
{
    protected InvoiceInterface $invoice;

    protected string $clearedXml;

    protected ?string $qrCode;

    /** @var array<string, mixed> */
    protected array $response;

    public function __construct(Submission $submission, string $clearedXml, ?string $qrCode)
    {
        $this->invoice = $submission->invoice;
        $this->clearedXml = $clearedXml;
        $this->qrCode = $qrCode;
        $this->response = $submission->response;
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
     *
     * @return array<string, mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Get validation errors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->response['validationResults']['errorMessages'] ?? [];
    }

    /**
     * Get validation warnings.
     *
     * @return array<int, array<string, mixed>>
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
     *
     * @return array{success: bool, status: string, uuid: string, invoice_number: string, qr_code: string|null, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
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
