<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends;

use Throwable;

/**
 * The app shell (a.k.a. container application).
 *
 * It owns the page skeleton and nothing else. For each slot of the layout
 * it asks the registry for a fragment, renders it, and stitches the results
 * together (server-side composition).
 */
final class AppShell
{
    /** @param list<string> $layout slot names, in page order */
    public function __construct(
        private readonly FragmentRegistry $registry,
        private readonly array $layout,
    ) {}

    /**
     * @param  array<string, mixed>  $context  shared state every fragment may read
     * @return array{html: string, assets: list<string>, failedSlots: list<string>}
     */
    public function renderPage(array $context): array
    {
        $sections = [];
        $assets = [];
        $failedSlots = [];

        foreach ($this->layout as $slot) {
            $fragment = $this->registry->fragmentFor($slot);

            if ($fragment === null) {
                continue; // nothing deployed for this slot (yet)
            }

            try {
                $body = $fragment->render($context);
                $assets = [...$assets, ...$fragment->assets()];
            } catch (Throwable) {
                // One team's bug must not take down the whole page.
                $body = '<p class="mfe-fallback">This section is temporarily unavailable.</p>';
                $failedSlots[] = $slot;
            }

            // Each fragment gets its own wrapper: a boundary for styles, for
            // error handling, and for knowing who to call when it breaks.
            $sections[] = sprintf('<section data-slot="%s" data-team="%s">%s</section>', $slot, $fragment->team(), $body);
        }

        return [
            'html' => '<main>'.implode('', $sections).'</main>',
            // Several fragments may ask for the same shared library; load it once.
            'assets' => array_values(array_unique($assets)),
            'failedSlots' => $failedSlots,
        ];
    }
}
