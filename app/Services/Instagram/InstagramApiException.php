<?php

namespace App\Services\Instagram;

use RuntimeException;

class InstagramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $fbCode = 0,
        protected ?int $fbSubcode = null,
        protected bool $rateLimited = false,
        protected bool $tokenExpired = false,
    ) {
        parent::__construct($message);
    }

    public function fbCode(): int
    {
        return $this->fbCode;
    }

    public function fbSubcode(): ?int
    {
        return $this->fbSubcode;
    }

    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }

    public function isTokenExpired(): bool
    {
        return $this->tokenExpired;
    }
}
