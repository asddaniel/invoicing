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
                "Le template '{$templateType}' est introuvable au chemin : {$templatePath}"
            );
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Récupérer les variables initiales présentes dans le template
        $templateVariables = $templateProcessor->getVariables();

        // 2. Traitement dynamique des lignes du tableau (Clonage intelligent)
        $this->processTableRows(
            $templateProcessor,
            $templateVariables,
            $data['rows'] ?? []
        );

        // Recharger les variables après l'opération cloneRow()
        $updatedVariables = $templateProcessor->getVariables();

        // 3. Remplissage des métadonnées et autres variables globales
        $this->fillMetadataAndVariables(
            $templateProcessor,
            $updatedVariables,
            $data
        );

        // 4. Sauvegarde du fichier généré
        $directory = storage_path('app/documents');

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $fileName = $templateType . '_' . time() . '.docx';
        $finalPath = $directory . DIRECTORY_SEPARATOR . $fileName;

        $templateProcessor->saveAs($finalPath);

        Log::info("Document généré avec succès : " . $finalPath);

        return $finalPath;
    }

    /**
     * Détecte l'ancre de ligne appropriée et remplit les cellules de manière adaptative.
     */
    private function processTableRows(
        TemplateProcessor $templateProcessor,
        array $templateVariables,
        array $rows
    ): void {
        if (empty($rows)) {
            return;
        }

        // Liste élargie d'ancres potentielles selon les 3 templates fournis
        $possibleAnchors = [
            'tab_part_number',
            'tab_part_description',
            'item',
            'part_number',
            'm_codes',
            'description'
        ];

        $cloneAnchor = null;

        foreach ($possibleAnchors as $anchor) {
            if (in_array($anchor, $templateVariables)) {
                $cloneAnchor = $anchor;
                break;
            }
        }

        if (!$cloneAnchor) {
            Log::warning("Aucune variable d'ancrage de tableau standard n'a été détectée dans ce template.");
            return;
        }

        // Cloner la ligne d'ancre autant de fois qu'il y a d'éléments dans $rows
        $templateProcessor->cloneRow($cloneAnchor, count($rows));

        // Récupérer la liste mise à jour des variables après le clonage (contenant désormais les suffixes #1, #2...)
        $variablesAfterClone = $templateProcessor->getVariables();

        foreach ($rows as $index => $rowData) {
            $rowNumber = $index + 1;

            // Normalisation des clés des données d'entrée pour le tableau
            $normalizedRowData = [];
            foreach ($rowData as $key => $value) {
                $normKey = strtolower(str_replace([' ', '-'], '_', $key));
                $normalizedRowData[$normKey] = $value;
            }

            // Parcourir les variables du template pour identifier celles de la ligne en cours
            foreach ($variablesAfterClone as $variable) {
                if (str_contains($variable, '#' . $rowNumber)) {
                    // Extraction de la racine de la variable (ex: "tab_part_number" depuis "tab_part_number#1")
                    $baseVariable = substr($variable, 0, strpos($variable, '#'));
                    $normalizedBase = strtolower(str_replace([' ', '-'], '_', $baseVariable));

                    $valueToInsert = '';

                    // 1. Essai de correspondance exacte
                    if (array_key_exists($normalizedBase, $normalizedRowData)) {
                        $valueToInsert = $normalizedRowData[$normalizedBase];
                    }
                    // 2. Correspondance adaptative (ex : mapper "part_number" vers "tab_part_number" ou inversement)
                    else {
                        foreach ($normalizedRowData as $key => $val) {
                            if ($key !== '' && (str_contains($normalizedBase, $key) || str_contains($key, $normalizedBase))) {
                                $valueToInsert = $val;
                                break;
                            }
                        }
                    }

                    $templateProcessor->setValue($variable, $valueToInsert ?? '');
                }
            }
        }
    }

    /**
     * Remplit les variables globales et gère les légères variations de structure.
     */
    private function fillMetadataAndVariables(
        TemplateProcessor $templateProcessor,
        array $templateVariables,
        array $data
    ): void {
        $flatData = [];

        $normalize = function($key) {
            return strtolower(str_replace([' ', '-'], '_', $key));
        };

        // Extraction et mise à plat des métadonnées
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $key => $value) {
                $flatData[$normalize($key)] = $value;
            }
        }

        // Extraction et mise à plat des autres données de premier niveau
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                $flatData[$normalize($key)] = $value;
            }
        }

        // Assignation des variables dans le template
        foreach ($templateVariables as $variable) {
            if (str_contains($variable, '#')) {
                continue; // Ignorer les variables liées aux tableaux
            }

            $normalizedVar = $normalize($variable);

            // 1. Match direct
            if (array_key_exists($normalizedVar, $flatData)) {
                $templateProcessor->setValue($variable, $flatData[$normalizedVar] ?? '');
            }
            // 2. Match partiel (ex: "client_name" ou "customer_name" vers "name" ou "customer")
            else {
                $matched = false;
                foreach ($flatData as $flatKey => $flatValue) {
                    if ($flatKey !== '' && (str_contains($normalizedVar, $flatKey) || str_contains($flatKey, $normalizedVar))) {
                        $templateProcessor->setValue($variable, $flatValue ?? '');
                        $matched = true;
                        break;
                    }
                }

                // Si aucune donnée ne correspond, vider la balise pour éviter l'affichage de la variable brute ${variable}
                if (!$matched) {
                    $templateProcessor->setValue($variable, '');
                }
            }
        }
    }
}
