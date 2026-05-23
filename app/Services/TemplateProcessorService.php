<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class TemplateProcessorService
{
    /**
     * Génère le document Word (.docx)
     * et le sauvegarde dans storage/app/documents
     */
    public function generateDocx(string $templateType, array $data): string
    {
        // 1. Charger le template
        $templatePath = resource_path("templates/{$templateType}.docx");

        if (!file_exists($templatePath)) {
            throw new \Exception(
                "Le template '{$templateType}' est introuvable : {$templatePath}"
            );
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Variables du template
        $templateVariables = $templateProcessor->getVariables();

        // 2. Gestion des lignes du tableau
        $this->processTableRows(
            $templateProcessor,
            $templateVariables,
            $data['rows'] ?? []
        );

        // Recharger les variables après cloneRow()
        $templateVariables = $templateProcessor->getVariables();

        // 3. Remplissage des variables
        $this->fillMetadataAndVariables(
            $templateProcessor,
            $templateVariables,
            $data
        );

        /**
         * 4. Sauvegarde réelle dans storage/app/documents
         */

        // Dossier réel
        $directory = storage_path('app/documents');

        // Créer le dossier s'il n'existe pas
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Nom unique du fichier
        $fileName = $templateType . '_' . time() . '.docx';

        // Chemin complet
        $finalPath = $directory . DIRECTORY_SEPARATOR . $fileName;

        // Sauvegarde
        $templateProcessor->saveAs($finalPath);

        Log::info("Document généré : " . $finalPath);

        return $finalPath;
    }

    /**
     * Gestion des lignes dynamiques du tableau
     */
    private function processTableRows(
        TemplateProcessor $templateProcessor,
        array $templateVariables,
        array $rows
    ): void {
        if (empty($rows)) {
            return;
        }

        $possibleAnchors = [
            'item',
            'part_number',
            'description',
            'm_codes'
        ];

        $cloneAnchor = null;

        foreach ($possibleAnchors as $anchor) {
            if (in_array($anchor, $templateVariables)) {
                $cloneAnchor = $anchor;
                break;
            }
        }

        if (!$cloneAnchor) {
            throw new \Exception(
                "Aucune variable d'ancrage détectée dans le template."
            );
        }

        // Cloner les lignes
        $templateProcessor->cloneRow($cloneAnchor, count($rows));

        // Injecter les données
        foreach ($rows as $index => $rowData) {

            $rowNumber = $index + 1;

            foreach ($rowData as $key => $value) {

                $normalizedKey = strtolower(
                    str_replace([' ', '-'], '_', $key)
                );

                $placeholder = $normalizedKey . '#' . $rowNumber;

                $templateProcessor->setValue(
                    $placeholder,
                    $value ?? ''
                );
            }
        }
    }

    /**
     * Remplit les variables globales
     */
    private function fillMetadataAndVariables(
        TemplateProcessor $templateProcessor,
        array $templateVariables,
        array $data
    ): void {

        $flatData = [];

        // Metadata
        if (isset($data['metadata']) && is_array($data['metadata'])) {

            foreach ($data['metadata'] as $key => $value) {

                $normalizedKey = strtolower(
                    str_replace([' ', '-'], '_', $key)
                );

                $flatData[$normalizedKey] = $value;
            }
        }

        // Champs globaux
        foreach ($data as $key => $value) {

            if (!is_array($value)) {

                $normalizedKey = strtolower(
                    str_replace([' ', '-'], '_', $key)
                );

                $flatData[$normalizedKey] = $value;
            }
        }

        // Remplissage
        foreach ($templateVariables as $variable) {

            if (str_contains($variable, '#')) {
                continue;
            }

            $normalizedVar = strtolower(
                str_replace([' ', '-'], '_', $variable)
            );

            $templateProcessor->setValue(
                $variable,
                $flatData[$normalizedVar] ?? ''
            );
        }
    }
}
