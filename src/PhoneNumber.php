<?php

declare(strict_types=1);

namespace Pam\WhatsApp;

final readonly class PhoneNumber
{
    public string $digits;

    public function __construct(string $number)
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (!is_string($digits) || strlen($digits) < 7 || strlen($digits) > 15) {
            throw new \InvalidArgumentException('Phone number must contain 7 to 15 digits including country code.');
        }
        if ($digits[0] === '0') {
            throw new \InvalidArgumentException('Phone number must start with the country code, without a leading zero.');
        }

        $this->digits = $digits;
    }

    public function contactId(): string
    {
        return $this->digits.'@c.us';
    }

    public function __toString(): string
    {
        return $this->digits;
    }
}
