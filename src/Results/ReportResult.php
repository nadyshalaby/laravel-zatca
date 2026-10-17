<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

class ReportResult
{
    protected InvoiceInterface $invoice;

    protected string $signedXml;

    protected string $qrCode;

    /** @var array<string, mixed> */
    protected array $response;

    public function __construct(Submission $submission, string $signedXml, string $qrCode)
    {
        $this->invoice = $submission->invoice;
        $this->signedXml = $signedXml;
        $this->qrCode = $qrCode;
        $this->response = $submission->response;
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
     * Get the reporting status.
     */
    public function getStatus(): string
    {
        return $this->response['reportingStatus'] ?? $this->response['status'] ?? 'UNKNOWN';
    }

    /**
     * Convert to array.
     *
     * @return array{success: bool, status: string, uuid: string, invoice_number: string, qr_code: string, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
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
