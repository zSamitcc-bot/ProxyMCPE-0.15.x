<?php

namespace kuoto\utils;

/**
 * Codigos ANSI centralizados.
 *
 * Antes cada clase que imprimia algo redeclaraba sus propias variables
 * ($aqua, $darkGray, ...) o metia los escapes a pelo dentro de las cadenas
 * ("\033[0;96m"), lo que hacia imposible cambiar la paleta en un solo sitio.
 */
abstract class TextFormat
{
    const RESET        = "\033[0m";
    const BOLD         = "\033[1m";
    const DIM          = "\033[2m";
    const UNDERLINE    = "\033[4m";

    const BLACK        = "\033[0;30m";
    const DARK_BLUE    = "\033[0;34m";
    const DARK_GREEN   = "\033[0;32m";
    const DARK_AQUA    = "\033[0;36m";
    const DARK_RED     = "\033[0;31m";
    const DARK_PURPLE  = "\033[0;35m";
    const GOLD         = "\033[0;33m";
    const GRAY         = "\033[0;37m";
    const DARK_GRAY    = "\033[0;90m";
    const BLUE         = "\033[0;94m";
    const GREEN        = "\033[0;92m";
    const AQUA         = "\033[0;96m";
    const RED          = "\033[0;91m";
    const LIGHT_PURPLE = "\033[0;95m";
    const YELLOW       = "\033[0;93m";
    const WHITE        = "\033[0;97m";

    /** Borra la linea actual y deja el cursor al principio. */
    const CLEAR_LINE   = "\r\033[K";

    /**
     * Quita todos los codigos ANSI de una cadena. Necesario para medir el
     * ancho real de una celda al pintar tablas.
     *
     * @param string $text
     * @return string
     */
    public static function clean($text)
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * Longitud visible (sin contar los codigos de color).
     *
     * @param string $text
     * @return int
     */
    public static function width($text)
    {
        return strlen(self::clean($text));
    }

    /**
     * Rellena a la derecha teniendo en cuenta los codigos de color.
     *
     * @param string $text
     * @param int $length
     * @param string $pad
     * @return string
     */
    public static function pad($text, $length, $pad = ' ')
    {
        $missing = $length - self::width($text);
        return $missing > 0 ? $text . str_repeat($pad, $missing) : $text;
    }

    /**
     * Activa el soporte de colores ANSI en la consola de Windows.
     */
    public static function enableWindowsColors()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return;
        }
        if (function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
            @sapi_windows_vt100_support(STDERR, true);
        }
    }
}
