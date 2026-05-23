<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateInvoiceRequest;
use App\Services\TemplateProcessorService;
use App\Services\PdfConverterService;
use Illuminate\Support\Facades\Log;
use Exception;

class InvoiceGeneratorController extends Controller
{
    protected TemplateProcessorService $templateProcessorService;
    protected PdfConverterService $pdfConverterService;

    /**
     * Injection des dépendances du traitement de template et du convertisseur.
     */
    public function __construct(
        TemplateProcessorService $templateProcessorService,
        PdfConverterService $pdfConverterService
    ) {
        $this->templateProcessorService = $templateProcessorService;
        $this->pdfConverterService = $pdfConverterService;
    }

    /**
     * Point d'entrée de l'API pour générer et renvoyer le PDF directement.
     */
    public function generate(GenerateInvoiceRequest $request)
    {
        $validated = $request->validated();
        $templateType = $validated['template_type'];

        $tempDocxPath = null;

        try {
            // 1. Génération du fichier Word temporaire (.docx)
            $tempDocxPath = $this->templateProcessorService->generateDocx($templateType, $validated);

            // 2. Conversion du fichier Word en PDF binaire via l'API PDF24
            $pdfContent = $this->pdfConverterService->convertDocxToPdf($tempDocxPath);

            // 3. Détermination d'un nom de fichier cohérent pour le téléchargement
            $docNumber = $validated['metadata']['invoice_number'] ?? 'DOC-' . date('Ymd-His');
            $filename = "{$templateType}_{$docNumber}.pdf";

            // 4. Nettoyage du fichier Word temporaire local (Succès)
           // $this->cleanupTemporaryFile($tempDocxPath);

            // 5. Envoi direct du PDF dans la réponse HTTP
            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (Exception $e) {
            // Enregistrement de l'anomalie dans les logs de Laravel
            Log::error("Échec lors de la génération du document PDF : " . $e->getMessage(), [
                'template_type' => $templateType,
                'exception' => $e,
            ]);

            // Nettoyage du fichier Word temporaire local (Échec)
            $this->cleanupTemporaryFile($tempDocxPath);

            // Retour d'un JSON clair indiquant l'erreur
            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique est survenue lors de la création du document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprime le fichier temporaire du disque s'il existe.
     */
    private function cleanupTemporaryFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            try {
                unlink($path);
            } catch (Exception $e) {
                Log::warning("Impossible de supprimer le fichier temporaire : " . $path);
            }
        }
    }
}
