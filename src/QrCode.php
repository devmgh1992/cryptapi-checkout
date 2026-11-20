<?php

namespace Nous\CryptApiCheckout;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** SVG QR without GD/Imagick (bacon/bacon-qr-code). */
class QrCode
{
    public static function svg(string $text, int $size = 190, int $margin = 1): ?string
    {
        try {
            $writer = new Writer(new ImageRenderer(new RendererStyle($size, $margin), new SvgImageBackEnd()));

            return $writer->writeString($text);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
