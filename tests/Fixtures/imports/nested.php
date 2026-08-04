<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return static function (Architecture $architecture): void {
    $architecture
        ->import(__DIR__ . '/layers.php')
        ->layer('Infrastructure', 'src/Infrastructure/');
};
