<?php

declare(strict_types=1);

namespace Patterns\Coordination\LogicalClocks;

/** How two events relate causally. */
enum Ordering
{
    /** The first could have influenced the second. */
    case Before;

    /** The second could have influenced the first. */
    case After;

    case Equal;

    /** Neither knew about the other: they happened independently. */
    case Concurrent;
}
