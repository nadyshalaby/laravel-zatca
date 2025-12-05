<?php

namespace Corecave\Zatca\Exceptions;

use Psr\Http\Message\ResponseInterface;

class ApiException extends ZatcaException
{
    protected ?ResponseInterface $response = null;

    protected array $zatcaErrors = [];

    protected array $zatcaWarnings = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?ResponseInterface $response = null,
        array $zatcaErrors = [],
        array $zatcaWarnings = []
    ) {
        parent::__construct($message, $code);
        $this->response = $response;
        $this->zatcaErrors = $zatcaErrors;
        $this->zatcaWarnings = $zatcaWarnings;
    }

    /**
     * Get the HTTP response.
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * Get ZATCA error messages.
     */
    public function getZatcaErrors(): array
    {
        return $this->zatcaErrors;
    }

    /**
     * Get ZATCA warning messages.
     */
    public function getZatcaWarnings(): array
    {
        return $this->zatcaWarnings;
    }

    /**
     * Create from API response.
     */
    public static function fromResponse(ResponseInterface $response, array $body = []): static
    {
        $statusCode = $response->getStatusCode();
        $message = $body['message'] ?? "ZATCA API request failed with status {$statusCode}";
        $errors = $body['validationResults']['errorMessages'] ?? [];
        $warnings = $body['validationResults']['warningMessages'] ?? [];

        return new static($message, $statusCode, $response, $errors, $warnings);
    }

    /**
     * Create for connection error.
     */
    public static function connectionFailed(string $reason): static
    {
        return new static("Failed to connect to ZATCA API: {$reason}");
    }

    /**
     * Create for timeout.
     */
    public static function timeout(): static
    {
        return new static('ZATCA API request timed out.');
    }

    /**
     * Create for authentication failure.
     */
    public static function authenticationFailed(): static
    {
        return new static('ZATCA API authentication failed. Please check your credentials.', 401);
    }

    /**
     * Create for rate limiting.
     */
    public static function rateLimited(): static
    {
        return new static('ZATCA API rate limit exceeded. Please try again later.', 429);
    }

    /**
     * Check if the error is due to authentication.
     */
    public function isAuthenticationError(): bool
    {
        return $this->getCode() === 401;
    }

    /**
     * Check if the error is due to rate limiting.
     */
    public function isRateLimitError(): bool
    {
        return $this->getCode() === 429;
    }

    /**
     * Check if the error is a validation error.
     */
    public function isValidationError(): bool
    {
        return count($this->zatcaErrors) > 0;
    }
}
