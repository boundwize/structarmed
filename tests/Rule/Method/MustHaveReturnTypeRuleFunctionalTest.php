<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

#[CoversClass(MustHaveReturnTypeRule::class)]
final class MustHaveReturnTypeRuleFunctionalTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testFlagsOnlyPublicMethodsMissingReturnTypeOnRealSources(): void
    {
        $basePath = $this->makeTempProject([
            'src/Domain/OrderService.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                final class OrderService
                {
                    public function __construct(private string $name)
                    {
                    }

                    public function process()
                    {
                    }

                    public function total(): int
                    {
                        return 0;
                    }

                    private function helper()
                    {
                    }
                }
                PHP,
        ]);

        $violations = $this->analyse($basePath)->forRule('domain.must_have_return_type');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('App\Domain\OrderService::process()', $violations[0]->message);
    }

    public function testIgnoresConstructorAndDestructorDeclaredWithDifferentCase(): void
    {
        // PHP method names are case-insensitive: __CONSTRUCT()/__Destruct() are
        // still ctor/dtor and may not declare a return type, so no violation.
        $basePath = $this->makeTempProject([
            'src/Domain/PaymentService.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                final class PaymentService
                {
                    public function __CONSTRUCT()
                    {
                    }

                    public function __Destruct()
                    {
                    }
                }
                PHP,
        ]);

        $violations = $this->analyse($basePath)->forRule('domain.must_have_return_type');

        $this->assertCount(0, $violations);
    }

    private function analyse(string $basePath): RuleViolationCollection
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->rule('domain.must_have_return_type', new MustHaveReturnTypeRule('Domain'));

        return (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential());
    }

    /** @param array<string, string> $files */
    private function makeTempProject(array $files): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-must-have-return-type');

        foreach ($files as $file => $contents) {
            $path = $basePath . '/' . $file;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $basePath;
    }
}
