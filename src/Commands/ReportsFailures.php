<?php

namespace Corecave\Zatca\Commands;

use Throwable;

/**
 * Prints a command's failure with every exception that caused it, so the console shows why, not only what.
 */
trait ReportsFailures
{
    /**
     * Print the failure as an error line, then one line per cause beneath it.
     */
    protected function reportFailure(string $what, Throwable $failure): void
    {
        $this->error("{$what}{$failure->getMessage()}");

        for ($cause = $failure->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $this->line("  Caused by: {$cause->getMessage()}");
        }
    }
}
