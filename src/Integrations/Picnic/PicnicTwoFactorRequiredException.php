<?php

namespace App\Integrations\Picnic;

class PicnicTwoFactorRequiredException extends \RuntimeException
{
    public function __construct(string $profileKey)
    {
        parent::__construct(sprintf(
            'Picnic profile "%s" requires 2FA. Call picnic_generate_2fa_code, then picnic_verify_2fa_code with the SMS code.',
            $profileKey,
        ));
    }
}
