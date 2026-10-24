<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Saves uploads under an extension the form allows. The `mimes` rule checks a
 * file's content, not its name, so a real PNG called "logo.html" passes it;
 * saved as .html under /storage it would be served as a page (stored XSS).
 * A name outside the allowed list is replaced by the detected type's.
 */
class Uploads
{
    public const IMAGES = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * @param  list<string>  $allowed  the form's `mimes` list
     */
    public static function extension(UploadedFile $file, array $allowed): string
    {
        $given = strtolower($file->getClientOriginalExtension());

        if (in_array($given, $allowed, true)) {
            return $given;
        }

        $detected = strtolower((string) $file->guessExtension());

        return in_array($detected, $allowed, true) ? $detected : 'bin';
    }

    /**
     * Store as "<directory>/<basename>.<safe extension>" on the disk.
     *
     * @param  list<string>  $allowed
     */
    public static function store(UploadedFile $file, string $directory, string $basename, array $allowed, string $disk): string
    {
        return $file->storeAs($directory, $basename.'.'.static::extension($file, $allowed), $disk);
    }
}
