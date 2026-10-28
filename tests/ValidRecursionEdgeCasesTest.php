<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Validation\Count;
use Pocta\DataMapper\Validation\Length;
use Pocta\DataMapper\Validation\NotBlank;
use Pocta\DataMapper\Validation\Valid;
use Pocta\DataMapper\Validation\Validator;

class ValidRecursionEdgeCasesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    // --- Cycles (A1) ---

    public function testCyclicGraphIsValidatedWithoutInfiniteRecursion(): void
    {
        $parent = new CycleParent();
        $child = new CycleChild();
        $child->name = '';
        $parent->child = $child;
        $child->parent = $parent;

        $errors = $this->validator->validate($parent, throw: false);

        $this->assertSame(['child.name'], array_keys($errors));
    }

    public function testSelfReferencingObjectIsValidatedOnce(): void
    {
        $node = new SelfReferencingNode();
        $node->name = '';
        $node->self = $node;

        $errors = $this->validator->validate($node, throw: false);

        $this->assertSame(['name'], array_keys($errors));
    }

    public function testSharedObjectInAcyclicGraphIsStillValidatedOnEveryPath(): void
    {
        $shared = new CycleChild();
        $shared->name = '';
        $holder = new SharedChildHolder();
        $holder->first = $shared;
        $holder->second = $shared;

        $errors = $this->validator->validate($holder, throw: false);

        $this->assertSame(['first.name', 'second.name'], array_keys($errors));
    }

    // --- Valid after a failing assert (A2) ---

    public function testValidRunsEvenWhenPrecedingAssertFails(): void
    {
        $item = new CycleChild();
        $item->name = '';
        $dto = new CountThenValidHolder();
        $dto->items = [$item];

        $errors = $this->validator->validate($dto, throw: false);

        $this->assertArrayHasKey('items', $errors);
        $this->assertArrayHasKey('items[0].name', $errors);
    }

    public function testValidBeforeFailingAssertReportsSameErrors(): void
    {
        $item = new CycleChild();
        $item->name = '';
        $dto = new ValidThenCountHolder();
        $dto->items = [$item];

        $errors = $this->validator->validate($dto, throw: false);

        $this->assertArrayHasKey('items', $errors);
        $this->assertArrayHasKey('items[0].name', $errors);
    }

    public function testOnlyFirstFailingAssertPerPropertyIsReported(): void
    {
        $dto = new TwoFailingAssertsHolder();
        $dto->code = '';

        $errors = $this->validator->validate($dto, throw: false);

        $this->assertSame(['code'], array_keys($errors));
        $this->assertStringContainsString('blank', strtolower($errors['code']));
    }
}

class CycleParent
{
    #[Valid]
    public ?CycleChild $child = null;
}

class CycleChild
{
    #[NotBlank]
    public string $name = 'ok';

    #[Valid]
    public ?CycleParent $parent = null;
}

class SelfReferencingNode
{
    #[NotBlank]
    public string $name = 'ok';

    #[Valid]
    public ?SelfReferencingNode $self = null;
}

class SharedChildHolder
{
    #[Valid]
    public ?CycleChild $first = null;

    #[Valid]
    public ?CycleChild $second = null;
}

class CountThenValidHolder
{
    /** @var array<int, CycleChild> */
    #[Count(min: 5)]
    #[Valid]
    public array $items = [];
}

class ValidThenCountHolder
{
    /** @var array<int, CycleChild> */
    #[Valid]
    #[Count(min: 5)]
    public array $items = [];
}

class TwoFailingAssertsHolder
{
    #[NotBlank]
    #[Length(min: 3)]
    public string $code = 'abc';
}
