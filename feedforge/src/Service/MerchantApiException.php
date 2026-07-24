<?php

declare(strict_types=1);

namespace FeedForge\Service;

/**
 * Thrown by MerchantApiHttpClient when Google's REST endpoint returns an error.
 *
 * Carries the parsed error body so callers can react to specific reason codes
 * (e.g. "ALREADY_EXISTS" or "PERMISSION_DENIED") without re-parsing the message.
 */
class MerchantApiException extends \RuntimeException
{
    private int $httpStatus = 0;
    private string $apiReason = '';
    /** @var array<string, mixed> */
    private array $apiResponse = [];

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(int $status): void
    {
        $this->httpStatus = $status;
    }

    public function getApiReason(): string
    {
        return $this->apiReason;
    }

    public function setApiReason(string $reason): void
    {
        $this->apiReason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiResponse(): array
    {
        return $this->apiResponse;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function setApiResponse(array $response): void
    {
        $this->apiResponse = $response;
    }

    /**
     * Convenience: was this a 404?
     */
    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }
}
