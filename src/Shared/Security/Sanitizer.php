<?php

namespace Kai\Tools\Shared\Security;

/**
 * Zentrale Bereinigung von Werten, die aus externen Quellen stammen und
 * ungeschützt in HTML-Attribute oder CSS-Deklarationen gelangen würden.
 */
final class Sanitizer
{
    /** Fallback-Farbe, wenn ein Wert kein gültiger Hex-Code ist. */
    public const string DEFAULT_COLOR = '#3b82f6';

    private function __construct()
    {
    }

    /**
     * Normalisiert eine Farbangabe auf ein sicheres Hex-Format (#rrggbb).
     *
     * Farbwerte werden in style-Attribute geschrieben. Da dort weder
     * htmlspecialchars noch ein CSS-Parser vor Wertinjektion schützt,
     * ist eine strikte Whitelist-Validierung die einzige sichere Option.
     */
    public static function hexColor(mixed $value, string $fallback = self::DEFAULT_COLOR): string
    {
        $color = strtolower(trim((string)$value));

        if (preg_match('/^#[0-9a-f]{6}$/', $color) === 1) {
            return $color;
        }

        // Kurzschreibweise #abc auf #aabbcc erweitern
        if (preg_match('/^#[0-9a-f]{3}$/', $color) === 1) {
            return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }

        return $fallback;
    }
}
