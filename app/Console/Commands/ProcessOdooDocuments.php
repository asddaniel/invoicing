<?php

// app/Console/Commands/ProcessOdooDocuments.php
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
    protected $description = 'Réconcilie les documents Odoo, produit les PDF et les envoie par email';

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

        $receivedLines = OdooPayload::where('model', $lineModel)
            ->where('parent_identifier', $parent->parent_identifier)
            ->whereIn('odoo_id', $expectedLineIds)
            ->get();

        if ($receivedLines->count() < count($expectedLineIds)) {
            return; // Données incompletes
        }

        $this->info("Dossier complet pour : " . $parent->parent_identifier);

        // Préparation du mappage ciblé des variables
        $normalizedData = $this->buildTemplateData($parent, $receivedLines);

        $tempDocxPath = $this->templateProcessor->generateDocx($normalizedData['template_type'], $normalizedData);
        $pdfContent = $this->pdfConverter->convertDocxToPdf($tempDocxPath);

        $recipientEmail = $this->resolveRecipientEmail($payload);
        $this->sendEmail($recipientEmail, $normalizedData, $pdfContent);

        if (file_exists($tempDocxPath)) {
            unlink($tempDocxPath);
        }

        $parent->update(['processed_at' => now()]);
        OdooPayload::where('model', $lineModel)
            ->where('parent_identifier', $parent->parent_identifier)
            ->whereIn('odoo_id', $expectedLineIds)
            ->update(['processed_at' => now()]);
    }

    private function buildTemplateData(OdooPayload $parent, $lines): array
    {
        $payload = $parent->payload;
        $model = $parent->model;
        $docNumber = $parent->parent_identifier;
        $issuerName = $payload['x_studio_from'] ?? 'Émetteur';

        $metadata = [];
        $rows = [];
        $templateType = '';

        if ($model === 'account.move') {
            $templateType = 'invoice';

            // --- CIBLAGE GLOBAL INVOICE ---
            $metadata = [
                'client_name'             => $payload['invoice_partner_display_name'] ?? 'Client',
                'adresse'                 => $payload['partner_id_address'] ?? '', // À ajuster selon les champs d'adresse reçus
                'client_destination_name' => $payload['x_studio_destination_name'] ?? '',
                'client_phone_number'     => $payload['x_studio_client_phone'] ?? '',
                'issuer_name'             => $issuerName,
                'vat_number'              => 'CD/LSH/RCCM/23-B-01177', // RCCM ou TVA de votre entreprise
                'tax_number'              => 'A2317664B',
                'invoice_number'          => $docNumber,
                'purchase_order'          => $payload['invoice_origin'] ?? $payload['ref'] ?? '',
                'client_vat_number'       => $payload['x_studio_client_vat'] ?? '',
                'order_date'              => $payload['invoice_date'] ?? $payload['date'] ?? '',
                'total_exclude_vat'       => number_format($payload['amount_untaxed'] ?? 0, 2, '.', ' '),
                'vat'                     => number_format($payload['amount_tax'] ?? 0, 2, '.', ' '),
                'total_include_vat'       => number_format($payload['amount_total'] ?? 0, 2, '.', ' '),
            ];

            // --- CIBLAGE LIGNES INVOICE ---
            foreach ($lines as $index => $line) {
                $linePayload = $line->payload;
                $rows[] = [
                    'item'         => (string)($index + 1),
                    'part_number'  => $this->extractPartNumber($linePayload['name'] ?? ''),
                    'description'  => $linePayload['name'] ?? '',
                    'unit_price'   => number_format($linePayload['price_unit'] ?? 0, 2, '.', ' '),
                    'qty'          => (string)($linePayload['quantity'] ?? 1),
                ];
            }

        } elseif ($model === 'sale.order') {
            $templateType = 'quote';

            // --- CIBLAGE GLOBAL QUOTATION ---
            $metadata = [
                'client_company_name' => $payload['partner_id'][1] ?? '',
                'client_name'         => $payload['partner_id'][1] ?? '',
                'client_phone'        => $payload['x_studio_phone'] ?? '',
                'client_email'        => $payload['x_studio_email'] ?? '',
                'client_address'      => $payload['x_studio_address'] ?? '',
                'date'                => $payload['date_order'] ?? '',
                'issuer_name'         => $issuerName,
                'invoice_number'      => $docNumber, // Dans le template Quote No est mappé sur `${invoice_number}`
                'quotation_name'      => $payload['name'] ?? 'Quotation',
                'total_exclude_vat'   => number_format($payload['amount_untaxed'] ?? 0, 2, '.', ' '),
                'vat'                 => number_format($payload['amount_tax'] ?? 0, 2, '.', ' '),
                'total_include_vat'   => number_format($payload['amount_total'] ?? 0, 2, '.', ' '),
                'delivery_delay'      => $payload['x_studio_delivery_delay'] ?? '4',
            ];

            // --- CIBLAGE LIGNES QUOTATION ---
            foreach ($lines as $index => $line) {
                $linePayload = $line->payload;
                $rows[] = [
                    'item'         => (string)($index + 1),
                    'm_codes'      => $linePayload['product_id'][1] ?? '',
                    'description'  => $linePayload['name'] ?? '',
                    'unit_price'   => number_format($linePayload['price_unit'] ?? 0, 2, '.', ' '),
                    'qty'          => (string)($linePayload['product_uom_qty'] ?? 1),
                    'discount'     => number_format($linePayload['discount'] ?? 0, 2) . '%',
                    'total_price'  => number_format($linePayload['price_subtotal'] ?? 0, 2, '.', ' '),
                ];
            }

        } elseif ($model === 'stock.picking') {
            $templateType = 'delivery_note';

            // --- CIBLAGE GLOBAL DELIVERY NOTE ---
            $metadata = [
                'order_date'      => $payload['scheduled_date'] ?? '',
                'order_number'    => $payload['origin'] ?? '',
                'delivery_note'   => $docNumber,
                'customer'        => $payload['partner_id'][1] ?? '',
                'dispatch_date'   => $payload['date_done'] ?? '',
                'delivery_method' => $payload['picking_type_id'][1] ?? '',
                'customer_name'   => $payload['partner_id'][1] ?? '',
                'customer_address'=> $payload['x_studio_customer_address'] ?? '',
                'delivery_days'   => $payload['x_studio_delivery_days'] ?? '15',
                'issuer_name'     => $issuerName,
            ];

            // --- CIBLAGE LIGNES DELIVERY NOTE ---
            foreach ($lines as $line) {
                $linePayload = $line->payload;
                $ordered = $linePayload['product_uom_qty'] ?? 1;
                $delivered = $linePayload['quantity'] ?? $ordered;
                $outstanding = max(0, $ordered - $delivered);

                $rows[] = [
                    'tab_part_number'      => $this->extractPartNumber($linePayload['name'] ?? ''),
                    'tab_part_description' => $linePayload['name'] ?? '',
                    'tab_ordered'          => (string)$ordered,
                    'tab_delivered'        => (string)$delivered,
                    'tab_outstanding'      => (string)$outstanding,
                    'tab_observation'      => $linePayload['note'] ?? '',
                ];
            }
        }

        return [
            'template_type' => $templateType,
            'metadata'      => $metadata,
            'rows'          => $rows,
        ];
    }

    /**
     * Extrait le numéro de référence produit si présent sous forme de crochets [79731573]
     */
    private function extractPartNumber(string $name): string
    {
        if (preg_match('/\[(.*?)\]/', $name, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function resolveRecipientEmail(array $payload): string
    {
        $usersMapRaw = env('ODOO_USERS_MAP', '{}');
        $usersMap = json_decode($usersMapRaw, true);

        if (!is_array($usersMap)) {
            $usersMap = [];
        }

        $fallbackEmail = env('ODOO_FALLBACK_EMAIL', 'devasddaniel@gmail.com');

        $possibleSenderKeys = [
            'x_studio_from',
            'x_studio_user_from',
            'user_id',
            'invoice_user_id',
            'create_uid'
        ];

        foreach ($possibleSenderKeys as $key) {
            if (isset($payload[$key])) {
                $value = $payload[$key];

                if (is_array($value) && isset($value[1])) {
                    $name = $value[1];
                    if (isset($usersMap[$name])) {
                        return $usersMap[$name];
                    }
                }

                if (is_string($value) && isset($usersMap[$value])) {
                    return $usersMap[$value];
                }
            }
        }

        return $fallbackEmail;
    }

    private function sendEmail(string $recipientEmail, array $data, string $pdfContent): void
    {
        $docNumber = $data['metadata']['invoice_number'] ?? $data['metadata']['delivery_note'] ?? 'DOCUMENT';
        $templateType = $data['template_type'];

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

        Log::info("E-mail envoyé depuis {$serverEmail} à {$recipientEmail} pour le document {$docNumber}");
    }
}
