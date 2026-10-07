<?php

namespace Tests\Support\Isolation;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\TestCase;

/**
 * How the isolation suite proves one route keeps tenant Alpha out of tenant
 * Bravo's data. Declared per route in tests/Feature/Isolation/Coverage/*.php.
 *
 * Every response a check produces is searched for Bravo's markers (IDs, names,
 * emails, codes, including in headers such as download filenames); finding one
 * fails the suite.
 */
final class RouteCoverage
{
    private function __construct(
        private readonly ?string $exemptReason,
        private readonly ?Closure $attempt,
    ) {}

    /**
     * The route never reads or writes tenant data (sign-in, the user's own
     * account, health checks). The reason is required and shows in review.
     */
    public static function exempt(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An isolation exemption needs a reason.');
        }

        return new self($reason, null);
    }

    /**
     * GET the route (it must have no required parameters) as every Alpha member,
     * and check that nothing of Bravo's appears. For pages and lists.
     */
    public static function visit(): self
    {
        return new self(null, null);
    }

    /**
     * A custom attack: fn (TwoTenants $tenants) runs with $this bound to the
     * test case. Act as an Alpha member against Bravo's records (guessed IDs,
     * exports, downloads, writes), assert the refusal, and return the
     * response(s) so they are also searched for Bravo's markers.
     *
     * @param  Closure(TwoTenants): (TestResponse<Response>|list<TestResponse<Response>>|null)  $attempt
     */
    public static function attempt(Closure $attempt): self
    {
        return new self(null, $attempt);
    }

    public function isExempt(): bool
    {
        return $this->exemptReason !== null;
    }

    public function check(TestCase $test, TwoTenants $tenants, Route $route): void
    {
        $responses = $this->attempt === null
            ? $this->visitAsEveryMember($test, $tenants, $route)
            : $this->runAttempt($test, $tenants);

        foreach ($responses as $response) {
            self::assertNothingOf($tenants->b, $response, $route);
        }
    }

    /**
     * Fail if any of the tenant's markers appear in the response body or headers.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function assertNothingOf(TenantFixture $tenant, TestResponse $response, ?Route $route = null): void
    {
        $haystack = self::content($response)."\n".$response->headers->__toString();
        $where = $route === null ? 'The response' : 'Route ['.IsolationCoverage::keyFor($route).']';

        foreach ($tenant->markers() as $marker) {
            // assertFalse rather than assertStringNotContainsString, so a failure doesn't print the whole page.
            Assert::assertFalse(
                str_contains($haystack, $marker),
                "{$where} leaked {$tenant->label}'s data: found [{$marker}] in the response (status {$response->status()}).",
            );
        }
    }

    /**
     * @return list<TestResponse<Response>>
     */
    private function visitAsEveryMember(TestCase $test, TwoTenants $tenants, Route $route): array
    {
        $key = IsolationCoverage::keyFor($route);

        Assert::assertContains('GET', $route->methods(), "RouteCoverage::visit() needs a GET route; use attempt() for [{$key}].");
        Assert::assertSame([], self::requiredParameters($route), "RouteCoverage::visit() can't fill route parameters; use attempt() for [{$key}].");

        $responses = [];

        foreach ($tenants->a->members() as $role => $member) {
            $response = $test->actingAs($member)->get('/'.ltrim($route->uri(), '/'));

            Assert::assertLessThan(500, $response->status(), "Visiting [{$key}] as Alpha's {$role} failed with status {$response->status()}.");
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * @return list<TestResponse<Response>>
     */
    private function runAttempt(TestCase $test, TwoTenants $tenants): array
    {
        /** @var Closure $attempt */
        $attempt = Closure::bind($this->attempt, $test, $test::class);
        $result = $attempt($tenants);

        return match (true) {
            $result === null => [],
            $result instanceof TestResponse => [$result],
            default => array_values($result),
        };
    }

    /**
     * @return list<string>
     */
    private static function requiredParameters(Route $route): array
    {
        preg_match_all('/\{(\w+)\}/', $route->uri(), $matches);

        return $matches[1];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private static function content(TestResponse $response): string
    {
        $base = $response->baseResponse;

        return match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse => $response->streamedContent(),
            default => (string) $base->getContent(),
        };
    }
}
