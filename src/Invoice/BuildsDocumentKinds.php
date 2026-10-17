<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;

/**
 * The invoice builder's starting points, one per kind of document: a standard or a simplified invoice, and a
 * credit or debit note of either.
 */
trait BuildsDocumentKinds
{
    /**
     * Create a standard (B2B) invoice builder.
     */
    public static function standard(): self
    {
        $builder = self::fromConfig();

        $builder->isSimplified = false;
        $builder->type = InvoiceType::INVOICE;
        $builder->subType = InvoiceSubType::STANDARD;

        return $builder;
    }

    /**
     * Create a simplified (B2C) invoice builder.
     */
    public static function simplified(): self
    {
        $builder = self::fromConfig();

        $builder->isSimplified = true;
        $builder->type = InvoiceType::INVOICE;
        $builder->subType = InvoiceSubType::SIMPLIFIED;

        return $builder;
    }

    /**
     * Create a credit note builder.
     */
    public static function creditNote(bool $simplified = false): self
    {
        $builder = self::fromConfig();

        $builder->isSimplified = $simplified;
        $builder->isCreditNote = true;
        $builder->type = InvoiceType::CREDIT_NOTE;
        $builder->subType = $simplified
            ? InvoiceSubType::SIMPLIFIED
            : InvoiceSubType::STANDARD;

        return $builder;
    }

    /**
     * Create a debit note builder.
     */
    public static function debitNote(bool $simplified = false): self
    {
        $builder = self::fromConfig();

        $builder->isSimplified = $simplified;
        $builder->isDebitNote = true;
        $builder->type = InvoiceType::DEBIT_NOTE;
        $builder->subType = $simplified
            ? InvoiceSubType::SIMPLIFIED
            : InvoiceSubType::STANDARD;

        return $builder;
    }
}
