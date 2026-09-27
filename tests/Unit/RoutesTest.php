<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use Yiisoft\Router\FastRoute\UrlGenerator;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;

use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Every route must answer on the URL it generates.
 *
 * A group prefix plus a "/" route compiles to "/prefix/", so "/admin" 404s even
 * though the layout links to it. This guards every route against that class of
 * mismatch, not just the admin index.
 */
final class RoutesTest extends \Codeception\Test\Unit
{
    private RouteCollection $routes;
    private UrlMatcher $matcher;
    private UrlGenerator $url;

    protected function _before(): void
    {
        $definitions = require codecept_root_dir() . 'config/common/routes.php';

        $collector = new RouteCollector();
        foreach ($definitions as $definition) {
            $collector->addRoute($definition);
        }

        $this->routes = new RouteCollection($collector);
        $this->matcher = new UrlMatcher($this->routes);
        $this->url = new UrlGenerator($this->routes);
    }

    public function testAdminIndexAnswersOnSlashlessUrl(): void
    {
        $this->assertMatches('/admin', 'admin');
    }

    public function testAdminIndexAlsoAnswersOnTrailingSlash(): void
    {
        $this->assertMatches('/admin/', 'admin-slash');
    }

    public function testEveryGeneratedUrlIsRoutable(): void
    {
        $unroutable = [];
        foreach ($this->flatRoutes() as $name => $route) {
            $arguments = [];
            if (preg_match_all('/\{(\w+)/', (string) $route->getData('pattern'), $matches) > 0) {
                foreach ($matches[1] as $argument) {
                    $arguments[$argument] = 'x';
                }
            }

            $path = $this->url->generate($name, $arguments);
            $methods = $route->getData('methods');
            $method = $methods === [] || in_array('GET', $methods, true) ? 'GET' : $methods[0];

            if (!$this->matcher->match(new ServerRequest($method, 'http://localhost' . $path))->isSuccess()) {
                $unroutable[] = "$name ($method $path)";
            }
        }

        assertNotEmpty($this->flatRoutes());
        assertSame([], $unroutable, 'Generated URLs must be routable.');
    }

    private function assertMatches(string $path, string $expectedName): void
    {
        $result = $this->matcher->match(new ServerRequest('GET', 'http://localhost' . $path));

        assertTrue($result->isSuccess(), "$path must be routable.");
        assertSame($expectedName, $result->route()->getData('name'));
    }

    /**
     * @return array<string, Route> Route name => route, groups flattened.
     */
    private function flatRoutes(): array
    {
        $flat = [];
        $walk = static function (array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                if ($node instanceof Route) {
                    $flat[(string) $node->getData('name')] = $node;
                } elseif (is_array($node)) {
                    $walk($node);
                }
            }
        };
        $walk($this->routes->getRouteTree(false));

        return $flat;
    }
}
