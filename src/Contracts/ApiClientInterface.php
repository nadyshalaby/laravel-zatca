<?php

namespace Corecave\Zatca\Contracts;

interface ApiClientInterface
{
    /**
     * Request a Compliance CSID (Certificate).
     *
     * @return array<string, mixed> ZATCA's JSON answer
     */
    public function requestComplianceCsid(string $csr, string $otp): array;

    /**
     * Submit an invoice for compliance check.
     *
     * @return array<string, mixed> ZATCA's JSON answer
     */
    public function submitComplianceInvoice(string $signedXml, string $invoiceHash, string $uuid): array;

    /**
     * Request a Production CSID.
     *
     * @return array<string, mixed> ZATCA's JSON answer
     */
    public function requestProductionCsid(string $complianceRequestId): array;

    /**
     * Renew a Production CSID.
     *
     * @return array<string, mixed> ZATCA's JSON answer
     */
    public function renewProductionCsid(string $csr, string $otp): array;

    /**
     * Report a simplified invoice (B2C).
     *
     * @return array<string, mixed> ZATCA's JSON answer
     */
    public function reportInvoice(string $signedXml, string $invoiceHash, string $uuid): array;

    /**
     * Clear a standard invoice (B2B).
     *
     * @return array<string, mixed> ZATCA's JSON answer
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
