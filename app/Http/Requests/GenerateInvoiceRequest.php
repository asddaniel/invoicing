<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateInvoiceRequest extends FormRequest
{
    /**
     * Détermine si l'utilisateur est autorisé à faire cette requête.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Règles de validation de la requête.
     */
    public function rules(): array
    {
        return [
            // Permet de choisir parmi 3 documents possibles (ex: 'invoice', 'delivery_note', 'quote')
            'template_type' => 'required|string|in:invoice,delivery_note,quote',
            
            // Les métadonnées globales du document (numéro de facture, date, client, etc.)
            'metadata' => 'required|array',
            'metadata.invoice_number' => 'required|string',
            'metadata.date' => 'required|string',
            'metadata.client_name' => 'required|string',
            'metadata.client_address' => 'nullable|string', // Optionnel
            
            // Structure du tableau dynamique : liste des entêtes de colonnes
            'headers' => 'required|array|min:1',
            'headers.*' => 'required|string', // Exemple: ["Description", "Quantité", "Prix Unitaire", "Total"]

            // Structure du tableau dynamique : les lignes de données
            // Chaque ligne doit être un tableau associatif où les clés correspondent aux headers ou à un index
            'rows' => 'required|array|min:1',
            'rows.*' => 'required|array',
            
            // Données de fin de document
            'delivery_delay' => 'nullable|string', // Optionnel
            'issuer_name' => 'required|string', // Nom de l'émetteur pour la signature
        ];
    }

    /**
     * Messages d'erreur personnalisés (Optionnel).
     */
    public function messages(): array
    {
        return [
            'template_type.in' => 'Le type de document doit être "invoice", "delivery_note" ou "quote".',
            'headers.required' => 'Les en-têtes du tableau principal sont requis.',
            'rows.required' => 'Les lignes de données du tableau sont requises.',
            'issuer_name.required' => 'Le nom de l\'émetteur pour la signature est requis.',
        ];
    }
}