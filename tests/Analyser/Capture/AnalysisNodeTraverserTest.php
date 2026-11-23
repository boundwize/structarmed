<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Capture;

use Boundwize\StructArmed\Analyser\Capture\AnalysisNodeCollector;
use Boundwize\StructArmed\Analyser\Capture\AnalysisNodeTraverser;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisNodeTraverser::class)]
final class AnalysisNodeTraverserTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    private const FILE = self::BASE_PATH . '/src/Domain/Order.php';

    private const CODE = <<<'PHP'
<?php

namespace App\Domain;

use App\Contract\Payable;
use App\Infrastructure\{Mailer, Logger as Log};
use function App\Support\format;

#[\Attribute()]
enum Status: string
{
    const PREFIX = 'order_';

    case Paid = self::PREFIX . 'paid';
}

interface HasTotal
{
    public function total(): int;
}

trait Notifies
{
    public function notify(): void
    {
        Mailer::send($this, static fn (Log $log): string => format('App\\' . 'Domain\\Order'));
    }
}

abstract class Order extends Base implements Payable, HasTotal
{
    use Notifies;

    public const LIMIT = 1_000;

    private ?Status $status = NULL;

    public function __construct(private readonly array $items = [])
    {
        [, $first] = $items;

        if ($first instanceof Payable || function_exists('App\Support\format')) {
            $this->status = Status::Paid;
        }

        $handler = new class extends Order {
            public function total(): int
            {
                return parent::total() + \count($_GET) + E_ALL;
            }
        };

        try {
            $reflection = (new \ReflectionClass(Order::class))->newInstance();
        } catch (\RuntimeException $exception) {
            exit(1);
        }
    }
}

function helper(int ...$values): float
{
    return \array_sum($values) * 1.5 + strlen(`ls`);
}

require __DIR__ . '/bootstrap.php';
PHP;

    public function testCollectsAndResolvesLikeTheNodeTraverser(): void
    {
        [$expectedCollector, $expectedAst] = $this->traverse(NodeTraverser::class);
        [$collector, $ast]                 = $this->traverse(AnalysisNodeTraverser::class);

        $this->assertEquals($expectedAst, $ast);
        $this->assertNotSame([], $collector->getClassNodes());
        $this->assertEquals($expectedCollector, $collector);
    }

    /**
     * @param class-string<NodeTraverser> $traverserClass
     * @return array{AnalysisNodeCollector, Node[]}
     */
    private function traverse(string $traverserClass): array
    {
        $parser                = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                   = $parser->parse(self::CODE) ?? [];
        $analysisNodeCollector = new AnalysisNodeCollector(
            new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH),
            withSignatures: true,
        );

        $analysisNodeCollector->setCurrentFile(self::FILE, $parser->getTokens());

        $nodeTraverser = new $traverserClass(new NameResolver(), $analysisNodeCollector);

        return [$analysisNodeCollector, $nodeTraverser->traverse($ast)];
    }
}
