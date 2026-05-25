<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('model');             // 'account.move', 'sale.order', 'stock.picking', ou leurs lignes
            $table->unsignedBigInteger('odoo_id'); // '_id' unique d'Odoo
            $table->string('parent_identifier')->nullable(); // Pour lier les lignes (ex: order_id, picking_id, ou move_name)
            $table->json('payload');             // Contenu brut reçu du webhook
            $table->timestamp('processed_at')->nullable(); // Marqué quand le PDF est généré et envoyé
            $table->timestamps();

            $table->index(['model', 'odoo_id']);
            $table->index('parent_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_payloads');
    }
};
