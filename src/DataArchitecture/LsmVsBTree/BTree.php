<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\LsmVsBTree;

/**
 * A B+ tree: values live in the leaves, internal nodes only guide the search.
 *
 * The tree is always balanced — every leaf is at the same depth — because
 * it grows from the ROOT, not from the leaves: a full node splits in two
 * and hands a separator key to its parent; when the root itself splits, a
 * new root is put on top.
 *
 * Each node stands for a disk page, so "nodes read" and "nodes written"
 * count the I/O a real engine would do.
 */
final class BTree
{
    private BTreeNode $root;

    public int $nodesRead = 0;

    public int $nodesWritten = 0;

    public int $splits = 0;

    /** @param int $maxKeys keys a node may hold before it must split (hundreds, for a real 8 KB page) */
    public function __construct(private readonly int $maxKeys = 4)
    {
        $this->root = new BTreeNode(leaf: true);
    }

    public function put(string $key, string $value): void
    {
        $split = $this->insert($this->root, $key, $value);

        if ($split !== null) {
            // The root overflowed: the tree becomes one level taller.
            [$separator, $right] = $split;

            $newRoot = new BTreeNode(leaf: false);
            $newRoot->keys = [$separator];
            $newRoot->children = [$this->root, $right];

            $this->root = $newRoot;
            $this->nodesWritten++;
        }
    }

    /** One node per level, from the root down to a leaf. */
    public function get(string $key): ?string
    {
        $node = $this->root;

        while (true) {
            $this->nodesRead++;

            if ($node->leaf) {
                $position = array_search($key, $node->keys, true);

                return $position === false ? null : $node->values[$position];
            }

            $node = $node->children[$this->childIndexFor($node, $key)];
        }
    }

    /**
     * All entries with $from <= key <= $to, in key order. Subtrees that
     * cannot contain keys in the range are never visited.
     *
     * @return array<string, string>
     */
    public function range(string $from, string $to): array
    {
        $results = [];

        $this->collect($this->root, $from, $to, $results);

        return $results;
    }

    /** Levels from root to leaf: the cost of every lookup. */
    public function height(): int
    {
        $height = 1;

        for ($node = $this->root; ! $node->leaf; $node = $node->children[0]) {
            $height++;
        }

        return $height;
    }

    /**
     * Insert into the subtree under $node.
     *
     * @return array{string, BTreeNode}|null when $node had to split: the separator key and the new right sibling
     */
    private function insert(BTreeNode $node, string $key, string $value): ?array
    {
        $this->nodesRead++; // a write first has to FIND the right page

        if ($node->leaf) {
            $position = $this->lowerBound($node->keys, $key);

            if (($node->keys[$position] ?? null) === $key) {
                $node->values[$position] = $value; // update in place
            } else {
                array_splice($node->keys, $position, 0, [$key]);
                array_splice($node->values, $position, 0, [$value]);
            }

            $this->nodesWritten++;

            return count($node->keys) > $this->maxKeys ? $this->splitLeaf($node) : null;
        }

        $index = $this->childIndexFor($node, $key);
        $childSplit = $this->insert($node->children[$index], $key, $value);

        if ($childSplit === null) {
            return null;
        }

        // The child split: adopt its new sibling and the key that separates them.
        [$separator, $right] = $childSplit;

        array_splice($node->keys, $index, 0, [$separator]);
        array_splice($node->children, $index + 1, 0, [$right]);

        $this->nodesWritten++;

        return count($node->keys) > $this->maxKeys ? $this->splitInternal($node) : null;
    }

    /** @return array{string, BTreeNode} */
    private function splitLeaf(BTreeNode $leaf): array
    {
        $middle = intdiv(count($leaf->keys), 2);

        $right = new BTreeNode(leaf: true);
        $right->keys = array_splice($leaf->keys, $middle);     // the upper half moves to the new node
        $right->values = array_splice($leaf->values, $middle);

        $this->splits++;
        $this->nodesWritten++;

        // In a B+ tree the separator is COPIED up: the key itself stays in the leaf.
        return [$right->keys[0], $right];
    }

    /** @return array{string, BTreeNode} */
    private function splitInternal(BTreeNode $node): array
    {
        $middle = intdiv(count($node->keys), 2);
        $separator = $node->keys[$middle];

        $right = new BTreeNode(leaf: false);
        $right->keys = array_splice($node->keys, $middle + 1);
        $right->children = array_splice($node->children, $middle + 1);

        array_pop($node->keys); // the separator MOVES up; it stays in neither half

        $this->splits++;
        $this->nodesWritten++;

        return [$separator, $right];
    }

    /** @param array<string, string> $results */
    private function collect(BTreeNode $node, string $from, string $to, array &$results): void
    {
        $this->nodesRead++;

        if ($node->leaf) {
            foreach ($node->keys as $position => $key) {
                if (strcmp($key, $from) >= 0 && strcmp($key, $to) <= 0) {
                    $results[$key] = $node->values[$position];
                }
            }

            return;
        }

        foreach ($node->children as $index => $child) {
            // Child $index holds keys k with: keys[index-1] <= k < keys[index].
            $lowest = $index === 0 ? null : $node->keys[$index - 1];
            $limit = $node->keys[$index] ?? null;

            $overlaps = ($limit === null || strcmp($from, $limit) < 0)
                && ($lowest === null || strcmp($to, $lowest) >= 0);

            if ($overlaps) {
                $this->collect($child, $from, $to, $results);
            }
        }
    }

    /** Which child to descend into: the number of separators that are <= the key. */
    private function childIndexFor(BTreeNode $node, string $key): int
    {
        $index = 0;

        while ($index < count($node->keys) && strcmp($key, $node->keys[$index]) >= 0) {
            $index++;
        }

        return $index;
    }

    /**
     * Position of the first key that is >= $key (where $key is, or should be inserted).
     *
     * @param  list<string>  $keys
     */
    private function lowerBound(array $keys, string $key): int
    {
        $position = 0;

        while ($position < count($keys) && strcmp($keys[$position], $key) < 0) {
            $position++;
        }

        return $position;
    }
}
