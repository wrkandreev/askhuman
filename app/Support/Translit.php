<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cyrillic-to-Latin transliteration for URL slugs.
 */
final class Translit
{
    /** @var array<string,string> */
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y',
        'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // neighbouring scripts that may slip in
        'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
    ];

    public static function toLatin(string $value): string
    {
        $lower = mb_strtolower($value);
        return str_replace(array_keys(self::MAP), array_values(self::MAP), $lower);
    }
}
