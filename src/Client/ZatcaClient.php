<?php

namespace Corecave\Zatca\Client;

use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Enums\Environment;
use Corecave\Zatca\Exceptions\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ZatcaClient implements ApiClientInterface
{
    protected Client $http;

    protected Environment $environment;

    protected ?CertificateInterface $certificate = null;

    protected array $config;

    public function __construct(string $environment = 'sandbox', array $config = [])
    {
        $this->environment = Environment::from($environment);
        $this->config = array_merge([
            'timeout' => 30,
            'connect_timeout' => 10,
            'retries' => 3,
            'retry_delay' => 1000,
            'verify_ssl' => true,
            'api_version' => 'V2',
        ], $config);

        $this->http = $this->createHttpClient();
    }

    /**
     * Create the HTTP client with retry middleware.
     */
    protected function createHttpClient(): Client
    {
        $stack = HandlerStack::create();

        // Add retry middleware
        $stack->push(Middleware::retry(
            $this->retryDecider(),
            $this->retryDelay()
        ));

        // Add logging middleware if enabled
        if (config('zatca.logging.log_api_calls', false)) {
            $stack->push($this->loggingMiddleware());
        }

        return new Client([
            'base_uri' => $this->environment->baseUrl().'/',
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'verify' => $this->config['verify_ssl'],
            'handler' => $stack,
            'http_errors' => false,
        ]);
    }

    /**
     * Request a Compliance CSID.
     */
    public function requestComplianceCsid(string $csr, string $otp): array
    {
        $response = $this->http->post('compliance', [
            'headers' => [
                'OTP' => $otp,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Version' => $this->config['api_version'],
            ],
            'json' => [
                'csr' => $this->encodeCsr($csr),
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Submit an invoice for compliance check.
     */
    public function submitComplianceInvoice(string $signedXml, string $invoiceHash, string $uuid): array
    {
        $this->ensureCertificate();

        $response = $this->http->post('compliance/invoices', [
            'headers' => $this->getAuthHeaders(),
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Request a Production CSID.
     */
    public function requestProductionCsid(string $complianceRequestId): array
    {
        $this->ensureCertificate();

        $response = $this->http->post('production/csids', [
            'headers' => $this->getAuthHeaders(),
            'json' => [
                'compliance_request_id' => $complianceRequestId,
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Renew a Production CSID.
     */
    public function renewProductionCsid(string $csr, string $otp): array
    {
        $this->ensureCertificate();

        $response = $this->http->patch('production/csids', [
            'headers' => array_merge($this->getAuthHeaders(), [
                'OTP' => $otp,
            ]),
            'json' => [
                'csr' => $this->encodeCsr($csr),
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Report a simplified invoice (B2C).
     */
    public function reportInvoice(string $signedXml, string $invoiceHash, string $uuid): array
    {
        $this->ensureCertificate();

        $response = $this->http->post('invoices/reporting/single', [
            'headers' => $this->getAuthHeaders(),
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Clear a standard invoice (B2B).
     */
    public function clearInvoice(string $signedXml, string $invoiceHash, string $uuid): array
    {
        $this->ensureCertificate();

        $response = $this->http->post('invoices/clearance/single', [
            'headers' => array_merge($this->getAuthHeaders(), [
                'Clearance-Status' => '1',
            ]),
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Set the certificate for authentication.
     */
    public function setCertificate(CertificateInterface $certificate): void
    {
        $this->certificate = $certificate;
    }

    /**
     * Get the current environment.
     */
    public function getEnvironment(): string
    {
        return $this->environment->value;
    }

    /**
     * Get authentication headers.
     */
    protected function getAuthHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Version' => $this->config['api_version'],
            'Accept-Language' => 'en',
        ];

        if ($this->certificate) {
            $headers['Authorization'] = 'Basic '.$this->certificate->getAuthCredentials();
        }

        return $headers;
    }

    /**
     * Ensure a certificate is set.
     *
     * @throws ApiException
     */
    protected function ensureCertificate(): void
    {
        if ($this->certificate === null) {
            throw ApiException::authenticationFailed();
        }
    }

    /**
     * Encode CSR for API submission.
     */
    protected function encodeCsr(string $csr): string
    {
        // Remove PEM headers and encode to base64
        $csrContent = str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csr
        );

        return trim($csrContent);
    }

    /**
     * Handle API response.
     *
     * @throws ApiException
     */
    protected function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $body = json_decode($response->getBody()->getContents(), true) ?? [];

        // Log response if enabled
        if (config('zatca.logging.enabled', true)) {
            $this->logResponse($statusCode, $body);
        }

        // Success responses
        if ($statusCode >= 200 && $statusCode < 300) {
            return $body;
        }

        // Handle specific error codes
        switch ($statusCode) {
            case 400:
                throw ApiException::fromResponse($response, $body);

            case 401:
                throw ApiException::authenticationFailed();

            case 429:
                throw ApiException::rateLimited();

            default:
                throw ApiException::fromResponse($response, $body);
        }
    }

    /**
     * Retry decision function.
     */
    protected function retryDecider(): callable
    {
        return function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Exception $exception = null
        ): bool {
            // Don't retry more than configured times
            if ($retries >= $this->config['retries']) {
                return false;
            }

            // Retry on connection errors
            if ($exception instanceof ConnectException) {
                return true;
            }

            // Retry on server errors (5xx)
            if ($response && $response->getStatusCode() >= 500) {
                return true;
            }

            // Retry on rate limiting
            if ($response && $response->getStatusCode() === 429) {
                return true;
            }

            return false;
        };
    }

    /**
     * Retry delay function.
     */
    protected function retryDelay(): callable
    {
        return function (int $retries): int {
            // Exponential backoff
            return $this->config['retry_delay'] * (2 ** $retries);
        };
    }

    /**
     * Logging middleware.
     */
    protected function loggingMiddleware(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $promise = $handler($request, $options);

                return $promise->then(
                    function (ResponseInterface $response) use ($request) {
                        $this->logRequest($request, $response);

                        return $response;
                    }
                );
            };
        };
    }

    /**
     * Log API request.
     */
    protected function logRequest(RequestInterface $request, ResponseInterface $response): void
    {
        $channel = config('zatca.logging.channel', 'stack');
        $level = config('zatca.logging.level', 'info');

        logger()->channel($channel)->log($level, 'ZATCA API Request', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'status' => $response->getStatusCode(),
        ]);
    }

    /**
     * Log API response.
     */
    protected function logResponse(int $statusCode, array $body): void
    {
        if (! config('zatca.logging.enabled', true)) {
            return;
        }

        $channel = config('zatca.logging.channel', 'stack');
        $level = $statusCode >= 400 ? 'error' : config('zatca.logging.level', 'info');

        // Remove sensitive data from logging
        $safeBody = $body;
        unset($safeBody['binarySecurityToken'], $safeBody['secret']);

        logger()->channel($channel)->log($level, 'ZATCA API Response', [
            'status' => $statusCode,
            'response' => $safeBody,
        ]);
    }

    /**
     * Check if the client has a valid certificate.
     */
    public function hasValidCertificate(): bool
    {
        return $this->certificate !== null && $this->certificate->isActive();
    }

    /**
     * Get the current certificate.
     */
    public function getCertificate(): ?CertificateInterface
    {
        return $this->certificate;
    }

    /**
     * Change the environment.
     */
    public function setEnvironment(string $environment): self
    {
        $this->environment = Environment::from($environment);
        $this->http = $this->createHttpClient();

        return $this;
    }
}
