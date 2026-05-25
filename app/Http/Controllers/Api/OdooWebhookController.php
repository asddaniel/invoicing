<?php

// app/Http/Controllers/Api/OdooWebhookController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OdooPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;


class OdooWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        if (!isset($data['_model']) || !isset($data['_id'])) {
            Log::warning("Webhook Odoo invalide reçu : ", $data);
            return response()->json(['message' => 'Données incomplètes (manque _model ou _id)'], 400);
        }

        try {
            $model = $data['_model'];
            $odooId = $data['_id'];
            $parentIdentifier = null;

            // Analyse et extraction des liaisons parent/enfant pour chaque type de document
            switch ($model) {
                // --- LIGNES DE DOCUMENTS ---
                case 'account.move.line':
                    // Une ligne de facture est liée au parent par 'move_name' (ex: FAC/2026/00004)
                    $parentIdentifier = $data['move_name'] ?? null;
                    break;

                case 'sale.order.line':
                    // Une ligne de devis est liée au parent par 'order_id'
                    $parentIdentifier = $data['order_id'] ?? null;
                    break;

                case 'stock.move':
                case 'stock.move_line':
                    // Une ligne de livraison est liée au parent par 'picking_id'
                    $parentIdentifier = $data['picking_id'] ?? null;
                    break;

                // --- EN-TÊTES DE DOCUMENTS ---
                case 'account.move':
                case 'sale.order':
                case 'stock.picking':
                    // Pour les parents, le "parent_identifier" est leur propre identifiant unique (nom ou ID)
                    $parentIdentifier = $data['name'] ?? $data['display_name'] ?? $odooId;
                    break;
            }

            // Enregistrement ou mise à jour de la donnée reçue
            OdooPayload::updateOrCreate(
                [
                    'model' => $model,
                    'odoo_id' => $odooId,
                ],
                [
                    'parent_identifier' => $parentIdentifier,
                    'payload' => $data,
                    'processed_at' => null, // Permet de re-traiter si une mise à jour survient
                ]
            );

            return response()->json(['success' => true, 'message' => 'Donnée enregistrée'], 200);

        } catch (Exception $e) {
            Log::error("Erreur d'ingestion Webhook Odoo : " . $e->getMessage(), ['payload' => $data]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
