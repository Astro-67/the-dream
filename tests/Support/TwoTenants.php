<?php

namespace Tests\Support;

/**
 * The isolation fixture (spec #1): two organizations, "Alpha" ($a) and "Bravo"
 * ($b), with two properties each and a member in every organization role.
 * Build it with twoTenants() in any feature test.
 */
final class TwoTenants
{
    public function __construct(
        public readonly TenantFixture $a,
        public readonly TenantFixture $b,
    ) {}

    public static function build(): self
    {
        return new self(TenantFixture::build('Alpha'), TenantFixture::build('Bravo'));
    }
}
