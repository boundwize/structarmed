<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function preg_match;
use function sprintf;

use const PREG_OFFSET_CAPTURE;

final class InlineHtmlOpeningTagMatcher
{
    private const ALLOWED_TAGS = 'php|xml|xml\-stylesheet';

    public static function invalidInlineHtmlTagOffset(string $text, int $offset = 0): ?int
    {
        $pattern = sprintf(
            '/<\?(?!(?:%s)(?:\s|$|\?>)|=)/',
            self::ALLOWED_TAGS,
        );

        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        /**
         * @var non-negative-int $tagOffset
         */
        $tagOffset = $matches[0][1];

        return $tagOffset;
    }
}
