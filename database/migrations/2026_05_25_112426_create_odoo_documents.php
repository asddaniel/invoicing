<?php

// database/migrations/xxxx_xx_xx_create_odoo_documents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type'); // 'invoice', 'delivery_note', 'quote'
            $table->string('document_number')->unique(); // ex: 'FAC/2026/00004'
            $table->json('payload'); // Contenu complet de la facture
            $table->string('status')->default('pending'); // 'pending', 'processing', 'completed', 'failed'
            $table->string('sender_identifier')->nullable(); // Dérivé de x_studio_from ou similaire
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['document_number', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_documents');
    }
};
