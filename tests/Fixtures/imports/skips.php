<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return static function (Architecture $architecture): void {
    $architecture->skipPath('tests/Fixtures/');
};
