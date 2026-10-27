<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Pocta\DataMapper\Attributes\MapProperty;

/**
 * PHP 8.4+ only (asymmetric visibility). Loaded only by tests guarded by RequiresPhp.
 */
class AsymmetricVisibilityDto
{
    public private(set) string $role = 'user';

    public protected(set) string $status = 'active';

    #[MapProperty]
    public private(set) string $nickname = 'none';

    public readonly ?string $id;

    public string $name = 'default';

    public function __construct(
        public private(set) string $tier = 'free',
    ) {
    }
}
