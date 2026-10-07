<?php

namespace Corecave\Zatca\Exceptions;

use Psr\Http\Message\ResponseInterface;

class ApiException extends ZatcaException
{
    protected ?ResponseInterface $response = null;

    /** @var array<array-key, mixed> */
    protected array $zatcaErrors = [];

    /** @var array<array-key, mixed> */
    protected array $zatcaWarnings = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?ResponseInterface $response = null) // law-exception: PHP's Exception takes its first three, and ZATCA's answer is the fourth
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
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
     *
     * @return array<array-key, mixed>
     */
    public function getZatcaErrors(): array
    {
        return $this->zatcaErrors;
    }

    /**
     * Get ZATCA warning messages.
     *
     * @return array<array-key, mixed>
     */
    public function getZatcaWarnings(): array
    {
        return $this->zatcaWarnings;
    }

    /**
     * Create from API response.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(ResponseInterface $response, array $body = []): static
    {
        $statusCode = $response->getStatusCode();
        $exception = new static(static::messageFrom($body, $statusCode), $statusCode, response: $response);

        $exception->zatcaErrors = static::errorsFrom($body);
        $exception->zatcaWarnings = $body['validationResults']['warningMessages']
            ?? $body['warnings']
            ?? $body['warningMessages']
            ?? [];

        return $exception;
    }

    /**
     * The message of a failed request, with ZATCA's error category and code when it names them.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function messageFrom(array $body, int $statusCode): string
    {
        // ZATCA API returns errors in different formats depending on the endpoint
        // Try multiple possible error message locations
        $message = $body['message']
            ?? $body['errorMessage']
            ?? $body['error']
            ?? "ZATCA API request failed with status {$statusCode}";

        // Include error code and category if available
        if (isset($body['errorCode']) || isset($body['errorCategory'])) {
            $errorCode = $body['errorCode'] ?? '';
            $errorCategory = $body['errorCategory'] ?? '';
            if ($errorCode || $errorCategory) {
                $message .= " [{$errorCategory}] Code: {$errorCode}";
            }
        }

        return $message;
    }

    /**
     * The validation errors of a failed request, a single error message wrapped in a list.
     *
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     */
    protected static function errorsFrom(array $body): array
    {
        // Try multiple possible error array locations
        $errors = $body['validationResults']['errorMessages']
            ?? $body['errors']
            ?? $body['errorMessages']
            ?? [];

        return is_string($errors) ? [['message' => $errors]] : $errors;
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
