<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Results\ComplianceCheck;
use Corecave\Zatca\Xml\InvoiceSigner;
use Exception;

/**
 * The compliance checks ZATCA asks of a device before its production certificate: a sample of each
 * document its invoice types call for, signed with its compliance certificate and sent to ZATCA's
 * compliance endpoint, one answer kept for each, a failure to reach an answer kept in its place.
 */
class ComplianceChecks
{
    private string $deviceId = '';

    /** @var array<string, mixed> */
    private array $seller = [];

    public function __construct(
        private readonly InvoiceSigner $signer,
        private readonly HashChainManager $chains,
    ) {}

    /**
     * The checks of one device, its samples naming the seller given, or the configured one when none.
     *
     * @param  array<string, mixed>  $seller
     */
    public function forDevice(string $deviceId, array $seller = []): static
    {
        $checks = clone $this;
        $checks->deviceId = $deviceId;
        $checks->seller = $seller;

        return $checks;
    }

    /**
     * Each sample the invoice types call for, sent in turn.
     *
     * @return list<ComplianceCheck>
     */
    public function run(ZatcaClient $client, CertificateInterface $certificate, string $invoiceTypes): array
    {
        return array_map(
            fn (array $sample, int $index): ComplianceCheck => $this->check($client, $certificate, ['sample' => $sample, 'number' => $index + 1]),
            $this->samples($invoiceTypes),
            array_keys($this->samples($invoiceTypes)),
        );
    }

    /**
     * The sample invoices the invoice types call for.
     *
     * @return list<array{type: string, subtype: string}>
     */
    public function samples(string $invoiceTypes): array
    {
        $samples = [];

        if (in_array($invoiceTypes, ['1000', '1100'], true)) {
            array_push($samples, ['type' => 'simplified', 'subtype' => 'invoice'], ['type' => 'simplified', 'subtype' => 'credit_note'], ['type' => 'simplified', 'subtype' => 'debit_note']);
        }

        if (in_array($invoiceTypes, ['0100', '1100'], true)) {
            array_push($samples, ['type' => 'standard', 'subtype' => 'invoice'], ['type' => 'standard', 'subtype' => 'credit_note'], ['type' => 'standard', 'subtype' => 'debit_note']);
        }

        return $samples;
    }

    /**
     * @param  array{sample: array{type: string, subtype: string}, number: int}  $run
     */
    private function check(ZatcaClient $client, CertificateInterface $certificate, array $run): ComplianceCheck
    {
        try {
            $invoice = $this->sampleInvoice($run['sample'], $run['number']);
            $signed = $this->signer->sign($invoice, $certificate);
            $response = $client->submitComplianceInvoice($signed->signedXml, $signed->hash, $invoice->getUuid());

            return new ComplianceCheck($run['sample'], $response['validationResults']['status'] ?? 'UNKNOWN', $response['validationResults']['errorMessages'] ?? []);
        } catch (Exception $failure) {
            return new ComplianceCheck($run['sample'], null, failure: $failure);
        }
    }

    /**
     * A sample invoice at the device's place in its chain, never recorded in it.
     *
     * @param  array{type: string, subtype: string}  $sample
     */
    private function sampleInvoice(array $sample, int $number): InvoiceInterface
    {
        $position = $this->chainPosition();
        $builder = $this->sampleBuilder($sample);
        $builder->setInvoiceNumber("SME0000{$number}")
            ->setIcv($position[0])
            ->setPreviousInvoiceHash($position[1]);

        if ($this->seller !== []) {
            $builder->setSeller($this->seller);
        }

        if ($sample['type'] !== 'simplified') {
            $builder->setBuyer([
                'name' => 'Test Buyer Company',
                'vat_number' => '300000000000003',
                'registration_number' => '1234567890',
                'address' => ['street' => 'Test Street', 'building' => '1234', 'city' => 'Riyadh', 'district' => 'Test District', 'postal_code' => '12345', 'country' => 'SA'],
            ]);
        }

        $builder->addLineItem(['name' => 'Test Product'] + ComplianceSample::LINE_ITEM)
            ->setVatGroups(ComplianceSample::VAT_GROUPS)
            ->setTotals(...ComplianceSample::TOTALS);

        return $builder->build();
    }

    /**
     * Where the device's chain stands, for its samples: its next counter and its previous hash, read under its
     * lock and let go at once, since a sample is never recorded in the chain.
     *
     * @return array{0: int, 1: string}
     */
    private function chainPosition(): array
    {
        $chain = $this->chains->forDevice($this->deviceId);

        return $chain->connection()->transaction(fn (): array => [$chain->getNextIcv(), $chain->getPreviousInvoiceHash()]);
    }

    /**
     * A builder for the sample's type and subtype; a credit or debit note refers to the original invoice with its reason.
     *
     * @param  array{type: string, subtype: string}  $sample
     */
    private function sampleBuilder(array $sample): InvoiceBuilder
    {
        $isSimplified = $sample['type'] === 'simplified';

        return match ($sample['subtype']) {
            'credit_note' => InvoiceBuilder::creditNote($isSimplified)->setOriginalInvoice('SME00001')->setReason('Compliance test credit note'),
            'debit_note' => InvoiceBuilder::debitNote($isSimplified)->setOriginalInvoice('SME00001')->setReason('Compliance test debit note'),
            default => $isSimplified ? InvoiceBuilder::simplified() : InvoiceBuilder::standard(),
        };
    }
}
