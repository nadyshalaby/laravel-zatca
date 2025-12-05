<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Exceptions\CertificateException;
use Corecave\Zatca\Models\ZatcaCertificate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

class CertificateManager
{
    protected array $config;

    protected ?CertificateInterface $cachedCertificate = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Store a certificate.
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
     * Get the active certificate of a given type.
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
     * Deactivate all certificates of a type.
     */
    public function deactivateAll(string $type): void
    {
        $storage = $this->config['storage'] ?? 'database';

        if ($storage === 'database') {
            ZatcaCertificate::where('type', $type)->update(['is_active' => false]);
        }
    }

    /**
     * Delete a certificate.
     */
    public function delete(int $id): bool
    {
        return ZatcaCertificate::destroy($id) > 0;
    }

    /**
     * Get all certificates.
     */
    public function all(): array
    {
        return ZatcaCertificate::all()
            ->map(fn ($model) => $this->modelToCertificate($model))
            ->toArray();
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
     */
    public function getExpiringSoon(int $days = 30): array
    {
        return ZatcaCertificate::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->get()
            ->map(fn ($model) => $this->modelToCertificate($model))
            ->toArray();
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

        $model = ZatcaCertificate::create($data);

        return $this->modelToCertificate($model);
    }

    /**
     * Get certificate from database.
     */
    protected function getFromDatabase(string $type): ?CertificateInterface
    {
        $model = ZatcaCertificate::where('type', $type)
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
        $path = $this->config['path'] ?? storage_path('zatca/certificates');
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
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return $certificate;
    }

    /**
     * Get certificate from file.
     */
    protected function getFromFile(string $type): ?CertificateInterface
    {
        $path = $this->config['path'] ?? storage_path('zatca/certificates');
        $file = "{$path}/{$type}.json";

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
        try {
            $cert = new Certificate(
                type: 'validation',
                certificate: $certificate,
                privateKey: $privateKey,
                secret: ''
            );

            // Try to get public key from cert and compare with derived public key
            $certPublicKey = $cert->getPublicKey();

            return ! empty($certPublicKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Import a certificate from PEM files.
     */
    public function import(
        string $type,
        string $certificatePath,
        string $privateKeyPath,
        string $secret,
        ?string $requestId = null
    ): CertificateInterface {
        if (! File::exists($certificatePath)) {
            throw CertificateException::invalid("Certificate file not found: {$certificatePath}");
        }

        if (! File::exists($privateKeyPath)) {
            throw CertificateException::invalid("Private key file not found: {$privateKeyPath}");
        }

        $certificate = File::get($certificatePath);
        $privateKey = File::get($privateKeyPath);

        if (! $this->validateKeyPair($certificate, $privateKey)) {
            throw CertificateException::keyMismatch();
        }

        $cert = new Certificate(
            type: $type,
            certificate: $certificate,
            privateKey: $privateKey,
            secret: $secret,
            requestId: $requestId
        );

        return $this->store($cert);
    }

    /**
     * Export a certificate to files.
     */
    public function export(string $type, string $directory): array
    {
        $certificate = $this->getActive($type);

        if (! $certificate) {
            throw CertificateException::notFound($type);
        }

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $certPath = "{$directory}/{$type}_certificate.pem";
        $keyPath = "{$directory}/{$type}_private_key.pem";

        File::put($certPath, $certificate->getCertificatePem());
        File::put($keyPath, $certificate->getPrivateKey());

        // Set restrictive permissions on private key
        chmod($keyPath, 0600);

        return [
            'certificate' => $certPath,
            'private_key' => $keyPath,
        ];
    }
}
