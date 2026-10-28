<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;

/**
 * Input arrays are finite trees, so a class nested in itself (directly or through other classes)
 * must map at any depth. Only a recursive PHP array reference is cut off by a depth limit.
 */
class TreeDenormalizationTest extends TestCase
{
    public function testSelfNestedClassMapsAtAnyDepth(): void
    {
        $node = (new Mapper())->fromArray(
            ['name' => 'a', 'child' => ['name' => 'b', 'child' => ['name' => 'c', 'child' => ['name' => 'd']]]],
            TreeNode::class
        );

        $this->assertSame('d', $node->child?->child?->child?->name);
    }

    public function testClassCycleThroughArrayElementsMapsAtAnyDepth(): void
    {
        $data = ['name' => 'Holding', 'assignments' => [['assigned' => [
            'name' => 'Firma', 'assignments' => [['assigned' => [
                'name' => 'Jan', 'assignments' => [],
            ]]],
        ]]]];

        $client = (new Mapper())->fromArray($data, TreeClient::class);

        $this->assertSame('Jan', $client->assignments[0]->assigned->assignments[0]->assigned->name);
    }

    public function testSiblingsOfSameClassStillMap(): void
    {
        $node = (new Mapper())->fromArray(
            ['name' => 'root', 'children' => [['name' => 'a'], ['name' => 'b', 'children' => [['name' => 'c']]]]],
            TreeNode::class
        );

        $this->assertSame('c', $node->children[1]->children[0]->name);
    }

    public function testRecursiveArrayReferenceIsRejectedByDepthLimit(): void
    {
        $data = ['name' => 'loop'];
        $data['child'] = &$data;

        try {
            (new Mapper())->fromArray($data, TreeNode::class);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Maximum nesting depth', $e->getMessage());
        }
    }

    public function testNestedValidationErrorKeepsItsPathInDeepTree(): void
    {
        try {
            (new Mapper())->fromArray(['name' => 'a', 'child' => ['name' => 'b', 'child' => ['child' => []]]], TreeNode::class);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('child.child.name', $e->getErrors());
        }
    }
}

class TreeNode
{
    public string $name;

    public ?TreeNode $child = null;

    /** @var array<int, TreeNode> */
    #[MapProperty(arrayOf: TreeNode::class)]
    public array $children = [];
}

class TreeClient
{
    public string $name = '';

    /** @var array<int, TreeAssignment> */
    #[MapProperty(arrayOf: TreeAssignment::class)]
    public array $assignments = [];
}

class TreeAssignment
{
    public TreeClient $assigned;
}
