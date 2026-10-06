<?php

namespace Corecave\Zatca\Hash;

use Corecave\Zatca\Exceptions\InvoiceException;
use Illuminate\Database\ConnectionInterface;

/**
 * Serializes invoice counter and hash chain writes, one lock per device of each database schema.
 *
 * Every workshop keeps its devices' counters and chains in its own schema, and each device has its own,
 * so the lock is keyed on the connection's current schema and the device: two workshops, or two devices
 * of one workshop, never wait for each other, while two invoices of the same device always do. It is a
 * transaction-level advisory lock, held until the outermost transaction ends, so the caller's transaction
 * must also record the invoice that takes the counter.
 */
class HashChainLock
{
    /**
     * Take the lock for the device in the connection's current schema.
     *
     * @throws InvoiceException when no transaction is open or the connection has no current schema
     */
    public function acquire(ConnectionInterface $connection, string $deviceId): void
    {
        if ($connection->transactionLevel() === 0) {
            throw InvoiceException::hashChainLockOutsideTransaction();
        }

        $schema = $connection->scalar('select current_schema()');

        if (! is_string($schema)) {
            throw InvoiceException::hashChainLockWithoutSchema();
        }

        $connection->select('select pg_advisory_xact_lock(hashtext(?))', ["{$schema}:{$deviceId}"]);
    }
}
