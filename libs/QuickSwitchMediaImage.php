<?php

declare(strict_types=1);

// Eigene Bilder (Medienobjekte) als Data-URL für die Kacheln von QuickSwitch und QuickLock
trait QuickSwitchMediaImage
{
    // Bilder werden als Data-URL an die Kachel übertragen; größere Bilder würden jede Vollaktualisierung ausbremsen.
    private function readImageDataUrl(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        if (!IPS_MediaExists($mediaId)) {
            $this->SendDebug('Images', 'Medium ' . $mediaId . ' existiert nicht', 0);
            return '';
        }
        $media = IPS_GetMedia($mediaId);
        if (($media['MediaType'] ?? -1) !== MEDIATYPE_IMAGE || (int) ($media['MediaSize'] ?? 0) > 524288) {
            $this->SendDebug('Images', 'Medium ' . $mediaId . ' ist kein Bild oder größer als 512 KB', 0);
            return '';
        }
        $content = IPS_GetMediaContent($mediaId);
        $signature = $content !== '' ? base64_decode(substr($content, 0, 16), true) : false;
        if (is_string($signature)) {
            if (substr($signature, 0, 3) === "\xFF\xD8\xFF") {
                return 'data:image/jpeg;base64,' . $content;
            }
            if (substr($signature, 0, 8) === "\x89PNG\r\n\x1A\n") {
                return 'data:image/png;base64,' . $content;
            }
        }
        $this->SendDebug('Images', 'Medium ' . $mediaId . ' ist kein JPEG oder PNG', 0);
        return '';
    }
}