<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OdooPayload;
use App\Services\TemplateProcessorService;
use App\Services\PdfConverterService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessOdooDocuments extends Command
{
    protected $signature = 'odoo:process';
    protected $description = 'Réconcilie les documents Odoo en attente, produit les PDF et les envoie par email';

    protected TemplateProcessorService $templateProcessor;
    protected PdfConverterService $pdfConverter;

    public function __construct(
        TemplateProcessorService $templateProcessor,
        PdfConverterService $pdfConverter
    ) {
        parent::__construct();
        $this->templateProcessor = $templateProcessor;
        $this->pdfConverter = $pdfConverter;
    }

    public function handle()
    {
        // Recherche des documents parents non traités
        $parents = OdooPayload::whereIn('model', ['account.move', 'sale.order', 'stock.picking'])
            ->whereNull('processed_at')
            ->get();

        foreach ($parents as $parent) {
            try {
                $this->processDocument($parent);
            } catch (Exception $e) {
                Log::error("Échec lors du traitement du document Odoo ID {$parent->odoo_id} : " . $e->getMessage());
            }
        }
    }

    private function processDocument(OdooPayload $parent)
    {
        $payload = $parent->payload;
        $model = $parent->model;

        $expectedLineIds = [];
        $lineModel = '';

        // Détection des identifiants de lignes attendues en fonction du type de document
        if ($model === 'account.move') {
            $expectedLineIds = $payload['invoice_line_ids'] ?? [];
            $lineModel = 'account.move.line';
        } elseif ($model === 'sale.order') {
            $expectedLineIds = $payload['order_line'] ?? [];
            $lineModel = 'sale.order.line';
        } elseif ($model === 'stock.picking') {
            $expectedLineIds = $payload['move_ids'] ?? $payload['move_line_ids'] ?? [];
            $lineModel = 'stock.move';
        }

        if (empty($expectedLineIds)) {
            Log::warning("Le document {$parent->parent_identifier} ({$model}) ne contient aucune ligne.");
            return;
        }

        // Récupération des lignes stockées en base de données pour ce document
        $receivedLines = OdooPayload::where('model', $lineModel)
            ->where('parent_identifier', $parent->parent_identifier)
            ->whereIn('odoo_id', $expectedLineIds)
            ->get();

        // Si le nombre de lignes reçues est inférieur au nombre attendu, nous attendons le passage suivant
        if ($receivedLines->count() < count($expectedLineIds)) {
            return;
        }

        $this->info("Dossier complet. Traitement de : " . $parent->parent_identifier);

        // Préparation et alignement des données pour le service de template
        $normalizedData = $this->buildTemplateData($parent, $receivedLines);

        // Génération du fichier Word temporaire puis conversion en PDF
        $tempDocxPath = $this->templateProcessor->generateDocx($normalizedData['template_type'], $normalizedData);
        $pdfContent = $this->pdfConverter->convertDocxToPdf($tempDocxPath);

        // Résolution de l'adresse e-mail destinataire à partir du fichier .env
        $recipientEmail = $this->resolveRecipientEmail($payload);

        // Envoi de l'e-mail avec l'adresse d'envoi système configurée dans le .env
        $this->sendEmail($recipientEmail, $normalizedData, $pdfContent);

        // Nettoyage du fichier Word temporaire
        if (file_exists($tempDocxPath)) {
            unlink($tempDocxPath);
        }

        // Marquage du document parent et de ses lignes comme traités
        $parent->update(['processed_at' => now()]);
        OdooPayload::where('model', $lineModel)
            ->where('parent_identifier', $parent->parent_identifier)
            ->whereIn('odoo_id', $expectedLineIds)
            ->update(['processed_at' => now()]);
    }

    private function buildTemplateData(OdooPayload $parent, $lines): array
    {
        $payload = $parent->payload;
        $type = 'invoice';
        $docNumber = $parent->parent_identifier;
        $clientName = 'Client';
        $date = now()->format('Y-m-d');

        if ($parent->model === 'account.move') {
            $type = 'invoice';
            $clientName = $payload['invoice_partner_display_name'] ?? 'Client';
            $date = $payload['invoice_date'] ?? $date;
        } elseif ($parent->model === 'sale.order') {
            $type = 'quote';
            $clientName = $payload['partner_id'][1] ?? $payload['display_name'] ?? 'Client';
            $date = $payload['date_order'] ?? $date;
        } elseif ($parent->model === 'stock.picking') {
            $type = 'delivery_note';
            $clientName = $payload['partner_id'][1] ?? $payload['display_name'] ?? 'Client';
            $date = $payload['date_done'] ?? $payload['scheduled_date'] ?? $date;
        }

        $rows = [];
        foreach ($lines as $line) {
            $linePayload = $line->payload;
            $rows[] = [
                'item' => $linePayload['display_name'] ?? $linePayload['name'] ?? '',
                'description' => $linePayload['name'] ?? '',
                'quantity' => $linePayload['quantity'] ?? $linePayload['product_uom_qty'] ?? 1,
                'price_unit' => $linePayload['price_unit'] ?? 0,
                'price_subtotal' => $linePayload['price_subtotal'] ?? 0,
            ];
        }

        return [
            'template_type' => $type,
            'metadata' => [
                'invoice_number' => $docNumber,
                'delivery_note' => $docNumber,
                'quote_no' => $docNumber,
                'date' => $date,
                'client_name' => $clientName,
            ],
            'headers' => ['Description', 'Quantité', 'Prix Unitaire', 'Total'],
            'rows' => $rows,
            'issuer_name' => $payload['x_studio_from'] ?? 'Émetteur',
        ];
    }

    /**
     * Parse le JSON de correspondance présent dans le .env pour trouver l'e-mail lié au nom de l'émetteur
     */
    private function resolveRecipientEmail(array $payload): string
    {
        // Chargement du dictionnaire JSON présent dans la variable d'environnement ODOO_USERS_MAP du fichier .env
        $usersMapRaw = env('ODOO_USERS_MAP', '{}');
        $usersMap = json_decode($usersMapRaw, true);

        if (!is_array($usersMap)) {
            $usersMap = [];
        }

        $fallbackEmail = env('ODOO_FALLBACK_EMAIL', 'devasddaniel@gmail.com');

        $possibleSenderKeys = [
            'x_studio_from',
            'x_studio_from1',
            'x_studio_user_from',
            'user_id',
            'invoice_user_id',
            'create_uid'
        ];

        foreach ($possibleSenderKeys as $key) {
            if (isset($payload[$key])) {
                $value = $payload[$key];

                // Si la valeur est au format [ID, Nom] (structure d'association standard Odoo)
                if (is_array($value) && isset($value[1])) {
                    $name = $value[1];
                    if (isset($usersMap[$name])) {
                        return $usersMap[$name];
                    }
                }

                // Si la valeur est directement une chaîne de caractères (ex: le nom de l'utilisateur)
                if (is_string($value) && isset($usersMap[$value])) {
                    return $usersMap[$value];
                }
            }
        }

        Log::warning("Aucune correspondance d'email trouvée dans ODOO_USERS_MAP pour l'émetteur du document. Email de secours utilisé.");
        return $fallbackEmail;
    }

    /**
     * Envoie l'e-mail en utilisant l'adresse de messagerie d'envoi du serveur définie dans le .env
     */
    private function sendEmail(string $recipientEmail, array $data, string $pdfContent): void
    {
        $docNumber = $data['metadata']['invoice_number'];
        $templateType = $data['template_type'];

        // Ces variables proviennent directement des configurations de Laravel liées aux clés MAIL_FROM_ADDRESS du .env
        $serverEmail = env('MAIL_FROM_ADDRESS');
        $serverName = env('MAIL_FROM_NAME', 'Serveur Odoo');

        Mail::send([], [], function ($message) use ($recipientEmail, $docNumber, $templateType, $pdfContent, $serverEmail, $serverName) {
            $message->from($serverEmail, $serverName)
                ->to($recipientEmail)
                ->subject("Votre document : {$templateType} {$docNumber}")
                ->html("<p>Bonjour, <br><br>Veuillez trouver ci-joint le document <strong>{$templateType} {$docNumber}</strong> généré automatiquement.</p>")
                ->attachData($pdfContent, "{$templateType}_{$docNumber}.pdf", [
                    'mime' => 'application/pdf',
                ]);
        });

        Log::info("E-mail envoyé depuis l'adresse du serveur ({$serverEmail}) à {$recipientEmail} pour le document {$docNumber}");
    }
}
