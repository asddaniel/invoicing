<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Cookie\CookieJar; // Importation nécessaire pour gérer les cookies
use Exception;

class PdfConverterService
{
    // On utilise le même hôte que le JS par défaut
    private string $defaultHost = 'filetools2.pdf24.org';

    /**
     * Convertit un fichier DOCX local en PDF en utilisant l'API de PDF24.
     *
     * @param string $docxPath Le chemin local du fichier DOCX à convertir
     * @return string Le flux binaire brut du fichier PDF généré
     */
    public function convertDocxToPdf(string $docxPath): string
    {
        if (!file_exists($docxPath)) {
            throw new Exception("Le fichier DOCX à convertir est introuvable sur le serveur.");
        }

        $host = $this->defaultHost;

        // Initialisation du gestionnaire de cookies pour maintenir la session
        $cookieJar = new CookieJar();

        Log::info("PDF24: Démarrage de l'upload du document " . basename($docxPath));

        // --- ÉTAPE 1 : Upload du fichier DOCX ---
        $uploadUrl = "https://{$host}/client.php?action=upload";

        $uploadResponse = Http::withOptions(['cookies' => $cookieJar])
            ->attach('file', file_get_contents($docxPath), basename($docxPath))
            ->post($uploadUrl);

        if (!$uploadResponse->successful()) {
            throw new Exception("L'upload du fichier sur le serveur de conversion PDF24 a échoué.");
        }

        $uploadData = $uploadResponse->json();

        if (empty($uploadData) || !isset($uploadData[0]['file'])) {
            throw new Exception("L'API de PDF24 n'a pas renvoyé les détails du fichier uploadé.");
        }

        Log::info("PDF24: Fichier uploadé avec succès.");

        // --- ÉTAPE 2 : Demande de conversion en PDF ---
        $convertUrl = "https://{$host}/client.php?action=convertToPdf";

        $payload = [
            'files' => $uploadData
        ];

        $convertResponse = Http::withOptions(['cookies' => $cookieJar])
            ->withBody(json_encode($payload), 'application/json')
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
        $statusUrl = "https://{$host}/client.php?action=getStatus&jobId={$jobId}";
        $isDone = false;
        $maxAttempts = 15;
        $attempt = 0;

        while (!$isDone && $attempt < $maxAttempts) {
            sleep(2);
            $attempt++;

            // On passe également le cookieJar ici pour que le serveur reconnaisse la session
            $statusResponse = Http::withOptions(['cookies' => $cookieJar])->get($statusUrl);

            if ($statusResponse->successful()) {
                $statusData = $statusResponse->json();

                if (($statusData['status'] ?? '') === 'done') {
                    $isDone = true;
                    Log::info("PDF24: Conversion complétée avec succès.");
                }
            } else {
                Log::warning("PDF24: Échec de la vérification du statut (tentative {$attempt}/{$maxAttempts})");
            }
        }

        if (!$isDone) {
            throw new Exception("Le délai d'attente pour la conversion du document a expiré ou une erreur est survenue.");
        }

        // --- ÉTAPE 4 : Téléchargement du résultat PDF ---
        $downloadUrl = "https://{$host}/client.php?mode=download&action=downloadJobResult&jobId={$jobId}";

        // Le cookieJar est requis ici aussi pour autoriser le téléchargement du fichier généré
        $downloadResponse = Http::withOptions(['cookies' => $cookieJar])->get($downloadUrl);

        if (!$downloadResponse->successful()) {
            throw new Exception("Le téléchargement du PDF converti a échoué.");
        }

        return $downloadResponse->body();
    }
}
