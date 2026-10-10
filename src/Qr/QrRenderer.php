<?php

declare(strict_types=1);

namespace Modulento\Card\Qr;

/**
 * Renders a QrEncoder::encode() module matrix as SVG or PNG. Pure rendering -
 * no encoding logic here. toSvg() needs no dependency; toPng() needs ext-gd.
 */
final class QrRenderer
{
    public static function toSvg(array $matrix, int $scale = 8, int $margin = 4): string
    {
        $size = count($matrix);
        $dim = ($size + $margin * 2) * $scale;

        $path = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $px = ($x + $margin) * $scale;
                    $py = ($y + $margin) * $scale;
                    $path .= "M{$px} {$py}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>';
        if ($path !== '') {
            $svg .= '<path d="' . $path . '" fill="#000"/>';
        }
        $svg .= '</svg>';

        return $svg;
    }

    public static function toPng(array $matrix, int $scale = 10, int $margin = 4): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('QrRenderer::toPng() requires the GD extension');
        }

        $size = count($matrix);
        $dim = ($size + $margin * 2) * $scale;

        $image = imagecreatetruecolor($dim, $dim);
        imagealphablending($image, false);
        imagesavealpha($image, false);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagecolortransparent($image, -1);
        imagefilledrectangle($image, 0, 0, $dim - 1, $dim - 1, $white);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $px = ($x + $margin) * $scale;
                    $py = ($y + $margin) * $scale;
                    imagefilledrectangle($image, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return (string) $bytes;
    }
}
