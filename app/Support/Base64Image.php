<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stockage d'images reçues en base64 (capture mobile) sur le disque `products`
 * (racine public/, servies directement sans storage:link).
 */
final class Base64Image
{
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
    ];

    /**
     * Décode et stocke une image base64 dans $folder (ex. "shops").
     * Retourne le chemin relatif (ex. "shops/abc.jpg").
     */
    public static function store(string $data, string $folder): string
    {
        // Préfixe éventuel "data:image/jpeg;base64,"
        if (str_contains($data, ',')) {
            $data = explode(',', $data, 2)[1];
        }

        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'photo_base64' => ['Image invalide ou trop volumineuse (max 5 Mo).'],
            ]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin);
        if (!isset(self::ALLOWED[$mime])) {
            throw ValidationException::withMessages([
                'photo_base64' => ["Le fichier envoyé n'est pas une image valide (JPG/JPEG, PNG, WebP, AVIF, GIF ou BMP acceptés)."],
            ]);
        }

        $path = $folder . '/' . uniqid('', true) . '.' . self::ALLOWED[$mime];
        Storage::disk('products')->put($path, $bin);

        return $path;
    }
}
