<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PdfConverterService
{
    // On utilise un sous-domaine par défaut de PDF24 pour l'upload initial
    private string $defaultHost = 'filetools13.pdf24.org';

    /**
     * Convertit un fichier DOCX local en PDF en utilisant l'API de PDF24.
     *
     * @param string $docxPath Le chemin local du fichier DOCX à convertir
     * @return string Le flux binaire brut (raw binary string) du fichier PDF généré
     */
    public function convertDocxToPdf(string $docxPath): string
    {
        if (!file_exists($docxPath)) {
            throw new Exception("Le fichier DOCX à convertir est introuvable sur le serveur.");
        }

        $host = $this->defaultHost;

        Log::info("PDF24: Démarrage de l'upload du document " . basename($docxPath));

        // --- ÉTAPE 1 : Upload du fichier DOCX ---
        $uploadUrl = "https://{$host}/client.php";
        
        $uploadResponse = Http::attach(
            'file', 
            file_get_contents($docxPath), 
            basename($docxPath)
        )->post($uploadUrl);

        if (!$uploadResponse->successful()) {
            throw new Exception("L'upload du fichier sur le serveur de conversion PDF24 a échoué.");
        }

        $uploadData = $uploadResponse->json();

        if (empty($uploadData) || !isset($uploadData[0]['file'])) {
            throw new Exception("L'API de PDF24 n'a pas renvoyé les détails du fichier uploadé.");
        }

        $fileInfo = $uploadData[0];
        
        // On met à jour le serveur hôte avec celui qui nous a été attribué par l'API
        $assignedHost = $fileInfo['host'] ?? $host;

        Log::info("PDF24: Fichier uploadé avec succès. Serveur attribué: {$assignedHost}");

        // --- ÉTAPE 2 : Demande de conversion en PDF ---
        $convertUrl = "https://{$assignedHost}/client.php?action=convertToPdf";
        
        // Le corps de la requête doit envoyer le tableau des informations de fichier au format JSON
        $convertResponse = Http::withBody(json_encode([$fileInfo]), 'application/json')
            ->post($convertUrl);

        if (!$convertResponse->successful()) {
            throw new Exception("La demande de conversion a échoué sur les serveurs de PDF24.");
        }

        $convertData = $convertResponse->json();
        $jobId = $convertData['jobId'] ?? null;

        if (!$jobId) {
            throw new Exception("L'API de PDF24 n'a pas retourné d'identifiant de conversion (jobId).");
        }

        Log::info("PDF24: Tâche de conversion lancée. ID de la tâche: {$jobId}");

        // --- ÉTAPE 3 : Suivi du statut (Polling) ---
        $statusUrl = "https://{$assignedHost}/client.php?action=getStatus&jobId={$jobId}";
        $isDone = false;
        $maxAttempts = 15; // Évite les boucles infinies (limité à ~15 secondes)
        $attempt = 0;

        while (!$isDone && $attempt < $maxAttempts) {
            sleep(1);
            $attempt++;

            $statusResponse = Http::get($statusUrl);
            
            if ($statusResponse->successful()) {
                $statusData = $statusResponse->json();
                
                if (($statusData['status'] ?? '') === 'done') {
                    $isDone = true;
                    Log::info("PDF24: Conversion complétée avec succès en {$attempt} seconde(s).");
                }
            } else {
                Log::warning("PDF24: Échec de la vérification du statut (tentative {$attempt}/{$maxAttempts})");
            }
        }

        if (!$isDone) {
            throw new Exception("Le délai d'attente pour la conversion du document a expiré.");
        }

        // --- ÉTAPE 4 : Téléchargement du résultat PDF ---
        $downloadUrl = "https://{$assignedHost}/client.php?mode=download&action=downloadJobResult&jobId={$jobId}";
        
        $downloadResponse = Http::get($downloadUrl);

        if (!$downloadResponse->successful()) {
            throw new Exception("Le téléchargement du PDF converti a échoué.");
        }

        return $downloadResponse->body();
    }
}