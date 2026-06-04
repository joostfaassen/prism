<?php

namespace App\Email;

class EmailIdentity
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly ?string $replyTo = null,
    ) {
    }
}
