<?php

namespace Nvahalik\Filer\Services;

use League\MimeTypeDetection\FinfoMimeTypeDetector;

class MimeType
{
    public FinfoMimeTypeDetector $detector;

    public static function detectMimeType(string $path, $contents)
    {
        $detector = new FinfoMimeTypeDetector();

        return $detector->detectMimeType($path, $contents);
    }

    public static function detectMimeTypeFromBuffer(string $contents)
    {
        $detector = new FinfoMimeTypeDetector();

        return $detector->detectMimeTypeFromBuffer($contents);
    }
}
