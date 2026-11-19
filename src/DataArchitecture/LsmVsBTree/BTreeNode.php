<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\LsmVsBTree;

/**
 * One page of the B-tree.
 *
 * A leaf holds keys and their values.
 * An internal node holds separator keys and children:
 *
 *     keys:          [ 30 | 60 ]
 *     children:   c0     c1     c2
 *                <30   30..59   >=60
 *
 * so a node with n keys always has n + 1 children.
 */
final class BTreeNode
{
    /** @var list<string> sorted */
    public array $keys = [];

    /** @var list<string> leaf only: values[i] belongs to keys[i] */
    public array $values = [];

    /** @var list<BTreeNode> internal only */
    public array $children = [];

    public function __construct(public readonly bool $leaf) {}
}
