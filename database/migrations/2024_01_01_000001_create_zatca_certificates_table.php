<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('zatca.tables.certificates', 'zatca_certificates'), function (Blueprint $table) {
            $table->id();

            // Certificate type: 'compliance' or 'production'
            $table->string('type', 20);

            // Certificate content (PEM format)
            $table->text('certificate');

            // Private key (encrypted)
            $table->text('private_key');

            // Certificate secret (encrypted)
            $table->text('secret');

            // Compliance request ID (used to request production CSID)
            $table->string('request_id')->nullable();

            // Certificate validity dates
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Whether this certificate is currently active
            $table->boolean('is_active')->default(true);

            // Additional metadata (JSON)
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('type');
            $table->index('is_active');
            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('zatca.tables.certificates', 'zatca_certificates'));
    }
};
