<?php

namespace App\Support;

class SummaryText
{
    public static function toPlainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/u', $text) ?: [];
        $segments = [];

        foreach ($lines as $line) {
            $line = preg_replace('/\s+/u', ' ', trim($line));

            if ($line !== null && $line !== '') {
                $segments[] = $line;
            }
        }

        if ($segments === []) {
            return null;
        }

        $plainText = array_shift($segments);

        foreach ($segments as $segment) {
            $plainText .= self::separatorFor($plainText, $segment) . $segment;
        }

        return $plainText;
    }

    public static function toPlainTextLimited(?string $value, int $maxCharacters): ?string
    {
        $plainText = self::toPlainText($value);

        if ($plainText === null) {
            return null;
        }

        if ($maxCharacters < 1 || mb_strlen($plainText) <= $maxCharacters) {
            return $plainText;
        }

        $trimmed = trim(mb_substr($plainText, 0, $maxCharacters));
        $lastSpace = mb_strrpos($trimmed, ' ');

        if ($lastSpace !== false && $lastSpace >= (int) floor($maxCharacters * 0.6)) {
            $trimmed = rtrim(mb_substr($trimmed, 0, $lastSpace), " \t\n\r\0\x0B,;:-");
        }

        return rtrim($trimmed);
    }

    private static function separatorFor(string $currentText, string $nextSegment): string
    {
        $currentText = rtrim($currentText);

        if ($currentText === '') {
            return '';
        }

        $lastCharacter = substr($currentText, -1);

        if (in_array($lastCharacter, ['.', '!', '?', ':', ';', '-', '>', '/', '('], true)) {
            return ' ';
        }

        if (preg_match('/^[\/>\-]/', $nextSegment) === 1) {
            return ' ';
        }

        return '. ';
    }
}
