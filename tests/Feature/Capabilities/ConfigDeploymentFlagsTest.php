<?php

namespace Bherila\McpLaravelBridge\Tests\Feature\Capabilities;

use Bherila\McpLaravelBridge\Capabilities\ConfigDeploymentFlags;
use Orchestra\Testbench\TestCase;

final class ConfigDeploymentFlagsTest extends TestCase
{
    public function test_nested_flags_cannot_reopen_what_their_parent_withdrew_and_a_bypass_passes_all(): void
    {
        $flags = new ConfigDeploymentFlags([
            'writes' => 'agent.writes',
            'invoices' => ['key' => 'agent.invoices', 'parents' => ['writes']],
        ]);

        config(['agent.writes' => false, 'agent.invoices' => true]);
        self::assertFalse($flags->enabled('invoices'), 'The inner flag cannot re-open the outer cutover');
        config(['agent.writes' => true]);
        self::assertTrue($flags->enabled('invoices'));
        self::assertFalse($flags->enabled('unknown'));

        $session = new ConfigDeploymentFlags(['writes' => 'agent.writes'], static fn (string $flag): bool => true);
        config(['agent.writes' => false]);
        self::assertTrue($session->enabled('writes'), 'A first-party bypass passes agent-only cutovers');
    }

    public function test_a_bypassed_child_still_answers_to_its_parent(): void
    {
        $flags = new ConfigDeploymentFlags([
            'writes' => 'agent.writes',
            'invoices' => ['key' => 'agent.invoices', 'parents' => ['writes']],
        ], static fn (string $flag): bool => $flag === 'invoices');

        config(['agent.writes' => false, 'agent.invoices' => false]);
        self::assertFalse($flags->enabled('invoices'), 'The global kill switch still withdraws a bypassed child');
        config(['agent.writes' => true]);
        self::assertTrue($flags->enabled('invoices'), 'The bypass covers the child\'s own value');
    }

    public function test_a_bypass_never_enables_an_undeclared_flag(): void
    {
        $flags = new ConfigDeploymentFlags([
            'writes' => 'agent.writes',
            'invoices' => ['key' => 'agent.invoices', 'parents' => ['writes', 'writse']],
        ], static fn (string $flag): bool => true);

        self::assertFalse($flags->enabled('unknown'), 'A misspelled flag stays off for a bypassed caller');
        self::assertFalse($flags->enabled('invoices'), 'So does a flag whose parent is misspelled');
        self::assertTrue($flags->enabled('writes'));
    }
}
