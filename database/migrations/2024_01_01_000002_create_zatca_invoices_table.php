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
        Schema::create(config('zatca.tables.invoices', 'zatca_invoices'), function (Blueprint $table) {
            $table->id();

            // UUID (Universally Unique Identifier)
            $table->uuid('uuid')->unique();

            // Invoice Counter Value (must be sequential)
            $table->unsignedBigInteger('icv')->unique();

            // Invoice number (from your system)
            $table->string('invoice_number');

            // Invoice type: standard, simplified, credit_note, debit_note
            $table->string('type', 30);

            // Invoice sub-type code
            $table->string('subtype', 10);

            // Invoice hash (SHA-256)
            $table->string('hash', 64);

            // Previous invoice hash
            $table->string('previous_hash', 64);

            // Status: pending, reported, cleared, rejected
            $table->string('status', 20)->default('pending');

            // Raw XML before signing
            $table->longText('xml')->nullable();

            // Signed XML
            $table->longText('signed_xml')->nullable();

            // QR code (base64 TLV)
            $table->text('qr_code')->nullable();

            // ZATCA response (JSON)
            $table->json('zatca_response')->nullable();

            // Reference to original invoice (for credit/debit notes)
            $table->string('reference_id')->nullable();

            // Financial amounts
            $table->decimal('total_amount', 15, 2);
            $table->decimal('vat_amount', 15, 2);

            $table->timestamps();

            // Indexes
            $table->index('invoice_number');
            $table->index('status');
            $table->index('type');
            $table->index(['status', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('zatca.tables.invoices', 'zatca_invoices'));
    }
};
