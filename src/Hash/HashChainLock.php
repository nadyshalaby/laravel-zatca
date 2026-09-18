<?php

namespace Corecave\Zatca\Hash;

use Corecave\Zatca\Exceptions\InvoiceException;
use Illuminate\Database\ConnectionInterface;

/**
 * Serializes invoice counter and hash chain writes, one lock per database schema.
 *
 * Every workshop keeps its own counter and chain in its own schema, so the lock is keyed on the
 * connection's current schema: two workshops never wait for each other, while two invoices in the
 * same workshop always do. It is a transaction-level advisory lock, held until the outermost
 * transaction ends.
 */
class HashChainLock
{
    /**
     * Take the lock for the connection's current schema.
     *
     * @throws InvoiceException when no transaction is open or the connection has no current schema
     */
    public function acquire(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() === 0) {
            throw InvoiceException::hashChainLockOutsideTransaction();
        }

        $schema = $connection->scalar('select current_schema()');

        if (! is_string($schema)) {
            throw InvoiceException::hashChainLockWithoutSchema();
        }

        $connection->select('select pg_advisory_xact_lock(hashtext(?))', [$schema]);
    }
}
