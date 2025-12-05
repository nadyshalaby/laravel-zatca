<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

class ProcessResult
{
    protected InvoiceInterface $invoice;

    protected ReportResult|ClearanceResult $result;

    protected string $processType;

    public function __construct(
        InvoiceInterface $invoice,
        ReportResult|ClearanceResult $result,
        string $processType
    ) {
        $this->invoice = $invoice;
        $this->result = $result;
        $this->processType = $processType;
    }

    /**
     * Check if the process was successful.
     */
    public function isSuccess(): bool
    {
        return $this->result->isSuccess();
    }

    /**
     * Get the invoice.
     */
    public function getInvoice(): InvoiceInterface
    {
        return $this->invoice;
    }

    /**
     * Get the underlying result.
     */
    public function getResult(): ReportResult|ClearanceResult
    {
        return $this->result;
    }

    /**
     * Get the process type ('reported' or 'cleared').
     */
    public function getProcessType(): string
    {
        return $this->processType;
    }

    /**
     * Check if invoice was reported (B2C).
     */
    public function wasReported(): bool
    {
        return $this->processType === 'reported';
    }

    /**
     * Check if invoice was cleared (B2B).
     */
    public function wasCleared(): bool
    {
        return $this->processType === 'cleared';
    }

    /**
     * Get the QR code.
     */
    public function getQrCode(): ?string
    {
        return $this->result->getQrCode();
    }

    /**
     * Get the final XML.
     */
    public function getXml(): string
    {
        if ($this->result instanceof ClearanceResult) {
            return $this->result->getClearedXml();
        }

        return $this->result->getSignedXml();
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->result->getErrors();
    }

    /**
     * Get validation warnings.
     */
    public function getWarnings(): array
    {
        return $this->result->getWarnings();
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess(),
            'process_type' => $this->processType,
            'uuid' => $this->invoice->getUuid(),
            'invoice_number' => $this->invoice->getInvoiceNumber(),
            'qr_code' => $this->getQrCode(),
            'errors' => $this->getErrors(),
            'warnings' => $this->getWarnings(),
        ];
    }
}
