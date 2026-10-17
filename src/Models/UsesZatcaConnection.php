<?php

namespace Corecave\Zatca\Models;

/**
 * Keeps a package model on the connection `zatca.connection` names, else on the default connection,
 * so an app whose seller keeps its device centrally reads and writes its certificates and chain there
 * even while a tenant's connection is the default.
 */
trait UsesZatcaConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('zatca.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }
}
