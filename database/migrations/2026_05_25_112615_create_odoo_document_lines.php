<?php
// database/migrations/xxxx_xx_xx_create_odoo_document_lines_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_document_lines', function (Blueprint $table) {
            $table->id();
            $table->string('document_number'); // Liaison logique via 'FAC/2026/00004'
            $table->unsignedBigInteger('odoo_line_id'); // L'id unique de la ligne (ex: 249, 11)
            $table->json('payload'); // Contenu complet de la ligne
            $table->timestamps();

            $table->unique(['document_number', 'odoo_line_id']);
            $table->index('document_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_document_lines');
    }
};
