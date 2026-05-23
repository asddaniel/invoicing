<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;

class TemplateProcessorService
{
    /**
     * Génère le document Word (.docx) à partir d'un template et de données dynamiques,
     * en préservant à 100% les styles, couleurs, polices et bordures définis dans le fichier Word original.
     *
     * @param string $templateType Type de document (invoice, delivery_note, quote)
     * @param array $data Les données validées de la requête
     * @return string Chemin temporaire du fichier DOCX généré
     */
    public function generateDocx(string $templateType, array $data): string
    {
        // 1. Charger le template Word correspondant (conserve les styles natifs)
        $templatePath = resource_path("templates/{$templateType}.docx");

        if (!file_exists($templatePath)) {
            throw new \Exception("Le template pour '{$templateType}' est introuvable au chemin: {$templatePath}");
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Récupérer toutes les variables définies initialement dans le template
        $templateVariables = $templateProcessor->getVariables();

        // 2. Traitement du tableau dynamique par clonage de ligne
        $this->processTableRows($templateProcessor, $templateVariables, $data['rows'] ?? []);

        // Récupérer à nouveau la liste des variables car le clonage a généré de nouvelles balises (ex: description#1, description#2...)
        $templateVariables = $templateProcessor->getVariables();

        // 3. Remplissage de toutes les autres variables et nettoyage des données optionnelles
        $this->fillMetadataAndVariables($templateProcessor, $templateVariables, $data);

        // 4. Sauvegarder le fichier Word généré dans un répertoire temporaire
        $tempDocxPath = 'storage/app/factdocx_' . '.docx';
        Log::alert($tempDocxPath);
       // $pathX="app";
        $templateProcessor->saveAs($tempDocxPath);
        //$templateProcessor->saveAs($pathX);

        return $tempDocxPath;
    }

    /**
     * Repère la balise d'ancrage du tableau du template, clone la ligne et injecte les données.
     */
    private function processTableRows(TemplateProcessor $templateProcessor, array $templateVariables, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // On cherche un marqueur de colonne présent dans l'un de vos 3 templates pour servir d'ancre au clonage de ligne
        $possibleAnchors = ['item', 'part_number', 'description', 'm_codes'];
        $cloneAnchor = null;

        foreach ($possibleAnchors as $anchor) {
            if (in_array($anchor, $templateVariables)) {
                $cloneAnchor = $anchor;
                break;
            }
        }

        if (!$cloneAnchor) {
            throw new \Exception("Aucune variable d'ancrage de tableau (item, part_number, description, m_codes) n'a été détectée dans le template.");
        }

        // Cloner la ligne du tableau autant de fois qu'il y a de lignes de données
        $templateProcessor->cloneRow($cloneAnchor, count($rows));

        // Remplir les lignes clonées en adaptant dynamiquement les clés reçues
        foreach ($rows as $index => $rowData) {
            $rowNumber = $index + 1;

            foreach ($rowData as $key => $value) {
                // Normaliser la clé (ex: "Part number" ou "M-Codes" -> "part_number" ou "m_codes")
                $normalizedKey = strtolower(str_replace([' ', '-'], '_', $key));

                // PHPWord génère des variables suffixées après le clonage (ex: description#1, description#2...)
                $placeholderWithIndex = $normalizedKey . '#' . $rowNumber;

                $templateProcessor->setValue($placeholderWithIndex, $value ?? '');
            }
        }
    }

    /**
     * Remplit toutes les métadonnées globales et nettoie les balises optionnelles absentes du payload.
     */
    private function fillMetadataAndVariables(TemplateProcessor $templateProcessor, array $templateVariables, array $data): void
    {
        // Aplatir toutes les données d'entrée dans un tableau simple clé-valeur pour faciliter l'association
        $flatData = [];

        // Extraire les métadonnées globales
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $key => $value) {
                $normalizedKey = strtolower(str_replace([' ', '-'], '_', $key));
                $flatData[$normalizedKey] = $value;
            }
        }

        // Extraire les champs globaux de premier niveau (delai, émetteur, totaux hors tableau...)
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                $normalizedKey = strtolower(str_replace([' ', '-'], '_', $key));
                $flatData[$normalizedKey] = $value;
            }
        }

        // Parcourir chaque variable présente dans le document Word
        foreach ($templateVariables as $variable) {
            // Ignorer les variables de lignes de tableau déjà traitées (qui contiennent un dièse '#')
            if (str_contains($variable, '#')) {
                continue;
            }

            // Normaliser le nom de la variable du template pour chercher sa correspondance dans les données reçues
            $normalizedVar = strtolower(str_replace([' ', '-'], '_', $variable));

            if (array_key_exists($normalizedVar, $flatData)) {
                $templateProcessor->setValue($variable, $flatData[$normalizedVar] ?? '');
            } else {
                // IMPORTANT : Si la donnée est optionnelle et absente de la requête, on la remplace par une chaîne vide
                // afin d'éviter de laisser des balises brutes de type "${client_address}" visible sur le PDF final
                $templateProcessor->setValue($variable, '');
            }
        }
    }
}
