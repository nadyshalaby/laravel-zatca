<?php

namespace Corecave\Zatca\Invoice;

/**
 * A document's totals as the caller priced them: the sum of the line nets (BT-106, written as BT-109 too, since
 * no document-level allowance is written), the VAT (BT-110, the sum of the groups' tax) and the total with VAT
 * (BT-112, written as the payable amount BT-115 too).
 */
final class DocumentTotals
{
    public function __construct(
        public readonly Amount $net,
        public readonly Amount $vat,
        public readonly Amount $gross,
    ) {}

    /**
     * @return array{net: string, vat: string, gross: string}
     */
    public function toArray(): array
    {
        return ['net' => $this->net->value, 'vat' => $this->vat->value, 'gross' => $this->gross->value];
    }
}
