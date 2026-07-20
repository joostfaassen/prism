<?php

namespace App\Picnic;

class PicnicTwoFactorRequiredException extends \RuntimeException
{
    public function __construct(string $accountKey)
    {
        parent::__construct(sprintf(
            'Picnic account "%s" requires 2FA. Call picnic_generate_2fa_code, then picnic_verify_2fa_code with the SMS code.',
            $accountKey,
        ));
    }
}
