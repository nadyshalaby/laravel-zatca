<?php

namespace Corecave\Zatca\Contracts;

interface ApiClientInterface
{
    /**
     * Request a Compliance CSID (Certificate).
     */
    public function requestComplianceCsid(string $csr, string $otp): array;

    /**
     * Submit an invoice for compliance check.
     */
    public function submitComplianceInvoice(string $signedXml, string $invoiceHash, string $uuid): array;

    /**
     * Request a Production CSID.
     */
    public function requestProductionCsid(string $complianceRequestId): array;

    /**
     * Renew a Production CSID.
     */
    public function renewProductionCsid(string $csr, string $otp): array;

    /**
     * Report a simplified invoice (B2C).
     */
    public function reportInvoice(string $signedXml, string $invoiceHash, string $uuid): array;

    /**
     * Clear a standard invoice (B2B).
     */
    public function clearInvoice(string $signedXml, string $invoiceHash, string $uuid): array;

    /**
     * Set the certificate for authentication.
     */
    public function setCertificate(CertificateInterface $certificate): void;

    /**
     * Get the current environment.
     */
    public function getEnvironment(): string;
}
