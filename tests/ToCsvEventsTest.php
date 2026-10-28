<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Events\PostNormalizeEvent;
use Pocta\DataMapper\Events\PreNormalizeEvent;
use Pocta\DataMapper\Mapper;

class ToCsvEventsTest extends TestCase
{
    public function testEachObjectIsNormalizedExactlyOnce(): void
    {
        $mapper = new Mapper();
        $pre = 0;
        $post = 0;
        $mapper->addEventListener(PreNormalizeEvent::class, function () use (&$pre): void {
            $pre++;
        });
        $mapper->addEventListener(PostNormalizeEvent::class, function () use (&$post): void {
            $post++;
        });

        $mapper->toCsv([new ToCsvRow('a'), new ToCsvRow('b'), new ToCsvRow('c')]);

        $this->assertSame(3, $pre);
        $this->assertSame(3, $post);
    }

    public function testSameObjectTwiceIsNormalizedPerOccurrence(): void
    {
        $mapper = new Mapper();
        $calls = 0;
        $mapper->addEventListener(PreNormalizeEvent::class, function () use (&$calls): void {
            $calls++;
        });
        $row = new ToCsvRow('a');

        $csv = $mapper->toCsv([$row, $row]);

        $this->assertSame(2, $calls);
        $this->assertSame("name\na\na\n", $csv);
    }

    public function testOutputIsUnchanged(): void
    {
        $csv = (new Mapper())->toCsv([new ToCsvRow('a'), new ToCsvRow('b')], ';', '"', '\\', true);

        $this->assertSame("name\na\nb\n", $csv);
    }

    public function testPostNormalizeChangesOfFirstObjectAreUsedForHeaderAndRow(): void
    {
        $mapper = new Mapper();
        $mapper->addEventListener(PostNormalizeEvent::class, function (PostNormalizeEvent $event): void {
            $event->data['extra'] = 'x';
        });

        $this->assertSame("name,extra\na,x\nb,x\n", $mapper->toCsv([new ToCsvRow('a'), new ToCsvRow('b')]));
    }
}

class ToCsvRow
{
    public function __construct(public string $name)
    {
    }
}
