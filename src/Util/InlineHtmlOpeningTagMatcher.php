<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function array_map;
use function implode;
use function preg_match;
use function preg_quote;
use function sprintf;

use const PREG_OFFSET_CAPTURE;

final class InlineHtmlOpeningTagMatcher
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'php',
        'xml',
        'xml-stylesheet',
    ];

    public static function invalidInlineHtmlTagOffset(string $text, int $offset = 0): ?int
    {
        $allowedTags = implode('|', array_map(
            static fn (string $target): string => preg_quote($target, '/'),
            self::ALLOWED_TAGS,
        ));
        $pattern     = sprintf(
            '/<\?(?!(?:%s)(?:\s|$|\?>)|=)/',
            $allowedTags,
        );

        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        return $matches[0][1];
    }
}
