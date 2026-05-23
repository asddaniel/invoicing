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

    public function __construct(
        TemplateProcessorService $templateProcessorService,
        PdfConverterService $pdfConverterService
    ) {
        $this->templateProcessorService = $templateProcessorService;
        $this->pdfConverterService = $pdfConverterService;
    }

    public function generate(GenerateInvoiceRequest $request)
    {
        $validated = $request->validated();
        $templateType = $validated['template_type'];

        $tempDocxPath = null;

        try {
            // 1. Génération du fichier Word temporaire (.docx)
            $tempDocxPath = $this->templateProcessorService->generateDocx($templateType, $validated);

            // 2. Conversion du fichier Word en PDF
            $pdfContent = $this->pdfConverterService->convertDocxToPdf($tempDocxPath);

            // 3. Identification dynamique du numéro de document pour le renommage
            $docNumber = $validated['metadata']['invoice_number']
                ?? $validated['metadata']['delivery_note']
                ?? $validated['metadata']['quote_no']
                ?? $validated['invoice_number']
                ?? $validated['delivery_note']
                ?? 'DOC-' . date('Ymd-His');

            $filename = "{$templateType}_{$docNumber}.pdf";

            // 4. Nettoyage du fichier local
            $this->cleanupTemporaryFile($tempDocxPath);

            // 5. Envoi de la réponse PDF
            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (Exception $e) {
            Log::error("Échec lors de la génération du document PDF : " . $e->getMessage(), [
                'template_type' => $templateType,
                'exception' => $e,
            ]);

            $this->cleanupTemporaryFile($tempDocxPath);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique est survenue lors de la création du document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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
