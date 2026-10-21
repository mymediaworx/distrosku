<?php

namespace App\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Stores images on the public disk, resized with GD so the longest side is <= MAX px. Re-encodes (strips EXIF / payloads). */
class ImageService
{
    public const MAX = 400;

    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public const MAX_KB = 5120;

    public function store(UploadedFile|string $file, string $dir): string
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        return $this->storeFromPath($path, $dir);
    }

    public function storeFromPath(string $path, string $dir): string
    {
        $info = @getimagesize($path);
        if (! $info || ! isset(self::MIMES[$info['mime']])) {
            throw new RuntimeException('Unsupported image type (use JPG, PNG, WebP or GIF).');
        }
        if (filesize($path) > self::MAX_KB * 1024) {
            throw new RuntimeException('Image is larger than 5 MB.');
        }
        $src = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false, 'image/gif' => @imagecreatefromgif($path),
        };
        if (! $src) {
            throw new RuntimeException('Could not read the image.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $dst = imagecreatetruecolor($nw, $nh);
        $png = $info['mime'] !== 'image/jpeg';
        if ($png) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $png ? imagepng($dst, null, 6) : imagejpeg($dst, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        $name = trim($dir, '/').'/'.Str::random(32).($png ? '.png' : '.jpg');
        Storage::disk('public')->put($name, $bytes); // creates directories as needed

        return $name;
    }

    /** Resize an already-stored public-disk file in place (used after Filament uploads). Returns the new path. */
    public function normalizeStored(?string $publicPath, string $dir): ?string
    {
        if (! $publicPath || ! Storage::disk('public')->exists($publicPath)) {
            return $publicPath;
        }
        $abs = Storage::disk('public')->path($publicPath);
        $info = @getimagesize($abs);
        if ($info && max($info[0], $info[1]) <= self::MAX && in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
            return $publicPath;
        }
        $new = $this->storeFromPath($abs, $dir);
        Storage::disk('public')->delete($publicPath);

        return $new;
    }

    /** data: URI for dompdf (no remote/chroot issues). */
    public static function dataUri(?string $publicPath): ?string
    {
        if (! $publicPath || ! Storage::disk('public')->exists($publicPath)) {
            return null;
        }
        $bytes = Storage::disk('public')->get($publicPath);
        $mime = str_ends_with($publicPath, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
