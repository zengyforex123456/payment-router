<?php

declare(strict_types=1);

namespace Converge\Core\Module;

/**
 * Fabric — 单个子系统容器
 */
class Fabric
{
    public function __construct(
        public readonly string $name,
        public readonly string $layer,
        public readonly string $path,
        public readonly string $desc,
        public readonly bool $required = false,
    ) {}
}
