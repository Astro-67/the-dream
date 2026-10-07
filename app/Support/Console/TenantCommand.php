<?php

namespace App\Support\Console;

use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A console command that acts for one tenant (ADR 0012). It gains
 * `--organization=<id>` (required) and `--property=<id>` options, and its
 * handle() runs inside Tenancy::within() for that organization and property,
 * with spatie's team ID set. Without an organization it refuses to run.
 *
 * Commands that work across organizations extend Command instead and enter
 * TenantContext::platform($reason) themselves.
 */
abstract class TenantCommand extends Command
{
    public function __construct()
    {
        parent::__construct();

        $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'ID of the organization to act for');
        $this->addOption('property', null, InputOption::VALUE_REQUIRED, 'ID of one of its properties to act for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = $this->option('organization');
        $propertyId = $this->option('property');

        if (! is_string($organizationId) || $organizationId === '') {
            $this->components->error('This command acts for one organization: pass --organization=<id> (and optionally --property=<id>).');

            return self::FAILURE;
        }

        $context = TenantContext::fromIds($organizationId, is_string($propertyId) && $propertyId !== '' ? $propertyId : null);

        return Tenancy::within($context, fn (): int => parent::execute($input, $output));
    }
}
