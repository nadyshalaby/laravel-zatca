<?php

namespace Corecave\Zatca\Commands;

/**
 * Reads the one-time password from the Fatoora portal a command needs: its `--otp` option, or asked for.
 */
trait AsksForOtp
{
    protected function otp(): string
    {
        $otp = $this->option('otp');

        return is_string($otp) && $otp !== '' ? $otp : (string) $this->ask('Enter OTP from FATOORA portal');
    }
}
