<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends;

/**
 * The contract between the app shell and every team's fragment.
 *
 * Keeping it this small is the point: the shell can compose any fragment
 * without knowing what it shows or how it was built.
 */
interface MicroFrontend
{
    /** The team that owns, builds and deploys this fragment. */
    public function team(): string;

    /**
     * Produce the fragment's HTML.
     *
     * @param  array<string, mixed>  $context  shared, read-only page state supplied by the shell (user, route params...)
     */
    public function render(array $context): string;

    /**
     * Scripts and stylesheets the fragment needs on the page.
     *
     * @return list<string>
     */
    public function assets(): array;
}
