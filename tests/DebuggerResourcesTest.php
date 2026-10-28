<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Debug\Debugger;

class DebuggerResourcesTest extends TestCase
{
    public function testConstructingDebuggerDoesNotOpenStream(): void
    {
        $before = count(get_resources('stream'));

        $debuggers = [new Debugger(), new Debugger(enabled: false), new Debugger(debugMode: true)];

        $this->assertCount(3, $debuggers);
        $this->assertSame($before, count(get_resources('stream')));
    }

    public function testDebugOutputStillGoesToConfiguredStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $debugger = new Debugger(debugMode: true);
        $debugger->setOutputStream($stream);

        $debugger->logOperation('fromArray', ['a' => 1], 'Foo');

        rewind($stream);
        $this->assertStringContainsString('Operation: fromArray -> Foo', (string) stream_get_contents($stream));
    }

    public function testLogsAreUnlimitedByDefault(): void
    {
        $debugger = new Debugger();

        for ($i = 0; $i < 50; $i++) {
            $debugger->logOperation("op{$i}", null);
        }

        $this->assertCount(50, $debugger->getLogs());
    }

    public function testLogsCanBeCappedKeepingNewestEntries(): void
    {
        $debugger = new Debugger(maxLogEntries: 3);

        for ($i = 0; $i < 5; $i++) {
            $debugger->logOperation("op{$i}", null);
        }

        $logs = $debugger->getLogs();
        $this->assertCount(3, $logs);
        $this->assertSame(['op2', 'op3', 'op4'], array_column($logs, 'operation'));
    }
}
