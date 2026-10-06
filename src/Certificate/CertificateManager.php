<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Exceptions\CertificateException;
use Corecave\Zatca\Models\ZatcaCertificate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\EC;
use phpseclib3\Exception\NoKeyLoadedException;
use phpseclib3\File\X509;

/**
 * A device's certificates. The manager the container holds belongs to no device: `forDevice()` gives the
 * certificates of the device that signs, and storing or reading one before that is refused.
 */
class CertificateManager
{
    /** @var array{storage?: string, path?: string, encryption_key?: string|null, auto_renew_days?: int|string} */
    protected array $config;

    private ?string $deviceId = null;

    /**
     * @param  array{storage?: string, path?: string, encryption_key?: string|null, auto_renew_days?: int|string}  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * The certificates of one device.
     */
    public function forDevice(string $deviceId): static
    {
        $manager = clone $this;
        $manager->deviceId = $deviceId;

        return $manager;
    }

    /**
     * Store a certificate as the device's active one of its type.
     *
     * @throws CertificateException when no device is chosen
     */
    public function store(CertificateInterface $certificate): CertificateInterface
    {
        $storage = $this->config['storage'] ?? 'database';

        if ($storage === 'file') {
            return $this->storeToFile($certificate);
        }

        return $this->storeToDatabase($certificate);
    }

    /**
     * The device's active certificate of a given type.
     *
     * @throws CertificateException when no device is chosen
     */
    public function getActive(string $type = 'production'): ?CertificateInterface
    {
        $storage = $this->config['storage'] ?? 'database';

        if ($storage === 'file') {
            return $this->getFromFile($type);
        }

        return $this->getFromDatabase($type);
    }

    /**
     * Get certificate by ID.
     */
    public function getById(int $id): ?CertificateInterface
    {
        $model = ZatcaCertificate::find($id);

        if (! $model) {
            return null;
        }

        return $this->modelToCertificate($model);
    }

    /**
     * Deactivate all the device's certificates of a type.
     *
     * @throws CertificateException when no device is chosen
     */
    public function deactivateAll(string $type): void
    {
        $storage = $this->config['storage'] ?? 'database';

        if ($storage === 'database') {
            ZatcaCertificate::where('device_id', $this->device())->where('type', $type)->update(['is_active' => false]);
        }
    }

    /**
     * Check if an active production certificate exists.
     */
    public function hasActiveProductionCertificate(): bool
    {
        $cert = $this->getActive('production');

        return $cert !== null && $cert->isActive();
    }

    /**
     * Check if an active compliance certificate exists.
     */
    public function hasActiveComplianceCertificate(): bool
    {
        $cert = $this->getActive('compliance');

        return $cert !== null && $cert->isActive();
    }

    /**
     * Get certificates that are expiring soon.
     *
     * @return array<int, CertificateInterface>
     */
    public function getExpiringSoon(int $days = 30): array
    {
        return ZatcaCertificate::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->get()
            ->map(fn ($model) => $this->modelToCertificate($model))
            ->all();
    }

    /**
     * Store certificate to database.
     */
    protected function storeToDatabase(CertificateInterface $certificate): CertificateInterface
    {
        // Deactivate existing certificates of the same type
        $this->deactivateAll($certificate->getType());

        // Encrypt sensitive data
        $data = $certificate->toArray();
        $data['private_key'] = Crypt::encryptString($data['private_key']);
        $data['secret'] = Crypt::encryptString($data['secret']);
        $data['device_id'] = $this->device();

        $model = ZatcaCertificate::create($data);

        return $this->modelToCertificate($model);
    }

    /**
     * Get certificate from database.
     */
    protected function getFromDatabase(string $type): ?CertificateInterface
    {
        $model = ZatcaCertificate::where('device_id', $this->device())
            ->where('type', $type)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $model) {
            return null;
        }

        return $this->modelToCertificate($model);
    }

    /**
     * Store certificate to file.
     */
    protected function storeToFile(CertificateInterface $certificate): CertificateInterface
    {
        $path = $this->deviceFolder();
        $type = $certificate->getType();

        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        // Encrypt sensitive data before storing
        $data = $certificate->toArray();
        $data['private_key'] = Crypt::encryptString($data['private_key']);
        $data['secret'] = Crypt::encryptString($data['secret']);

        File::put(
            "{$path}/{$type}.json",
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        return $certificate;
    }

    /**
     * Get certificate from file.
     */
    protected function getFromFile(string $type): ?CertificateInterface
    {
        $file = "{$this->deviceFolder()}/{$type}.json";

        if (! File::exists($file)) {
            return null;
        }

        $data = json_decode(File::get($file), true);

        if (! $data) {
            return null;
        }

        // Decrypt sensitive data
        $data['private_key'] = Crypt::decryptString($data['private_key']);
        $data['secret'] = Crypt::decryptString($data['secret']);

        return Certificate::fromArray($data);
    }

    /**
     * The folder of the device's certificate files, one file per type.
     *
     * @throws CertificateException when no device is chosen
     */
    protected function deviceFolder(): string
    {
        return ($this->config['path'] ?? storage_path('zatca/certificates')).'/'.$this->device();
    }

    /**
     * @throws CertificateException when no device is chosen
     */
    protected function device(): string
    {
        return $this->deviceId ?? throw CertificateException::noDevice();
    }

    /**
     * Convert Eloquent model to Certificate instance.
     */
    protected function modelToCertificate(ZatcaCertificate $model): Certificate
    {
        return Certificate::fromArray([
            'type' => $model->type,
            'certificate' => $model->certificate,
            'private_key' => Crypt::decryptString($model->private_key),
            'secret' => Crypt::decryptString($model->secret),
            'request_id' => $model->request_id,
            'issued_at' => $model->issued_at,
            'expires_at' => $model->expires_at,
            'is_active' => $model->is_active,
            'metadata' => $model->metadata ?? [],
        ]);
    }

    /**
     * Validate a certificate and private key pair.
     */
    public function validateKeyPair(string $certificate, string $privateKey): bool
    {
        $certificatePublicKey = $this->certificatePublicKey($certificate);

        if ($certificatePublicKey === null) {
            return false;
        }

        // The pair matches when the certificate carries the public half of this very private key
        try {
            $keyPublicKey = EC::loadPrivateKey($privateKey)->getPublicKey()->toString('PKCS8');
        } catch (NoKeyLoadedException $e) {
            return false;
        }

        return hash_equals($certificatePublicKey, $keyPublicKey);
    }

    /**
     * The public key a certificate carries, in PKCS8, or null when the text is not a certificate.
     */
    protected function certificatePublicKey(string $certificate): ?string
    {
        $x509 = new X509;

        if ($x509->loadX509($certificate) === false) {
            return null;
        }

        $publicKey = $x509->getPublicKey();

        return $publicKey === false ? null : $publicKey->toString('PKCS8');
    }

    /**
     * Import a certificate from PEM files.
     */
    public function import(CertificateOrigin $origin, CertificateFiles $files, string $secret): CertificateInterface
    {
        if (! File::exists($files->certificatePath)) {
            throw CertificateException::invalid("Certificate file not found: {$files->certificatePath}");
        }

        if (! File::exists($files->privateKeyPath)) {
            throw CertificateException::invalid("Private key file not found: {$files->privateKeyPath}");
        }

        $certificate = File::get($files->certificatePath);
        $privateKey = File::get($files->privateKeyPath);

        if (! $this->validateKeyPair($certificate, $privateKey)) {
            throw CertificateException::keyMismatch();
        }

        $cert = new Certificate($origin, new CertificateCredentials($certificate, $privateKey, $secret));

        return $this->store($cert);
    }
}
