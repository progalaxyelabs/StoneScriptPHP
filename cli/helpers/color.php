<?php

if (!class_exists('Color')) {
    class Color {
        const RED    = "\033[0;31m";
        const GREEN  = "\033[0;32m";
        const YELLOW = "\033[1;33m";
        const BLUE   = "\033[0;34m";
        const NC     = "\033[0m";

        public static function red(string $text): string    { return self::RED    . $text . self::NC; }
        public static function green(string $text): string  { return self::GREEN  . $text . self::NC; }
        public static function yellow(string $text): string { return self::YELLOW . $text . self::NC; }
        public static function blue(string $text): string   { return self::BLUE   . $text . self::NC; }
    }
}
