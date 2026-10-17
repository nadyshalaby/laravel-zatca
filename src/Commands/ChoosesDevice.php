<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\CertificateManager;

/**
 * Reads the ZATCA device a command acts for from its `--device` option: its certificates and its invoice
 * chain are its own, so a command that names none is refused.
 */
trait ChoosesDevice
{
    protected ?string $deviceId = null;

    /**
     * The certificates of the device the command names, or null, with the error printed, when it names none.
     */
    protected function deviceCertificates(CertificateManager $certificates): ?CertificateManager
    {
        $device = $this->option('device');

        if (! is_string($device) || $device === '') {
            $this->error('Name the ZATCA device with --device=<id>: its certificates and its invoice chain are its own.');

            return null;
        }

        $this->deviceId = $device;

        return $certificates->forDevice($device);
    }
}
