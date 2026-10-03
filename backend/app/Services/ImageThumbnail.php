<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * A small copy of a picture, for showing it in a thread.
 *
 * A photo off a phone is three or four megabytes and four thousand pixels
 * wide. The bubble it goes in is a couple of hundred pixels across, so every
 * one of those megabytes was being spent to draw something the size of a
 * stamp - and spent again by every person in the group, on whatever
 * connection they happened to be on. Three photos in a row sat on "Loading…"
 * for as long as that took.
 *
 * So the thread gets a thumbnail and the full picture is fetched only when
 * somebody opens it. Made once and kept, because making it is the expensive
 * part and the original never changes.
 *
 * Everything here fails soft: if the image cannot be read, or this PHP has no
 * GD, the caller is told so and sends the original. A slow picture is a great
 * deal better than a missing one.
 */
class ImageThumbnail
{
    /** The long edge, in pixels. Twice the widest a bubble gets, for retina. */
    public const EDGE = 640;

    /** What GD can be trusted to decode here. */
    private const READABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Too many pixels to open safely.
     *
     * GD holds four bytes a pixel while it works, so a 50-megapixel panorama
     * is 200MB of memory and takes the whole request with it. Pictures that
     * big are rare and are served whole instead - slow, but alive.
     */
    private const MAX_PIXELS = 40_000_000;

    /**
     * The path of this attachment's thumbnail, or null if there cannot be one.
     *
     * Returns a path on the local disk, the same disk the originals live on.
     */
    public function for(string $path, ?string $mime): ?string
    {
        if (! in_array((string) $mime, self::READABLE, true) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk('local');
        $thumb = 'chat-thumbs/' . sha1($path) . '.jpg';

        if ($disk->exists($thumb)) {
            return $thumb;
        }

        if (! $disk->exists($path)) {
            return null;
        }

        try {
            $made = $this->shrink($disk->path($path));
        } catch (\Throwable) {
            // A truncated upload, a format GD will not take, a file that
            // claims to be a picture and is not.
            return null;
        }

        if ($made === null) {
            return null;
        }

        $disk->put($thumb, $made);

        return $thumb;
    }

    /**
     * Throw away the copy, because the original has gone.
     *
     * Called wherever an attachment's bytes are deleted. A thumbnail is still
     * the picture - smaller, but the same picture - so a deleted message that
     * left its thumbnail behind would be a deletion that did not delete.
     */
    public function forget(string $path): void
    {
        Storage::disk('local')->delete('chat-thumbs/' . sha1($path) . '.jpg');
    }

    /** The shrunken JPEG as a string, or null if it was not worth shrinking. */
    private function shrink(string $file): ?string
    {
        $size = @getimagesize($file);
        if (! $size) {
            return null;
        }

        [$width, $height] = $size;
        if ($width * $height > self::MAX_PIXELS) {
            return null;
        }

        // Already small enough that a copy would save nobody anything.
        if (max($width, $height) <= self::EDGE) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($file));
        if ($source === false) {
            return null;
        }

        try {
            $scaled = imagescale($source, ...($width >= $height
                ? [self::EDGE, -1]
                : [(int) round($width * self::EDGE / $height), self::EDGE]));

            if ($scaled === false) {
                return null;
            }

            try {
                ob_start();
                // Flattened onto white: a JPEG has no transparency, and the
                // alternative is a black background where a PNG was clear.
                $flat = imagecreatetruecolor(imagesx($scaled), imagesy($scaled));
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $scaled, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled));
                imagejpeg($flat, null, 78);
                imagedestroy($flat);

                return (string) ob_get_clean();
            } finally {
                imagedestroy($scaled);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
