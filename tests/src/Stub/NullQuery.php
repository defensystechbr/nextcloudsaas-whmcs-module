<?php
declare(strict_types=1);

namespace NextcloudSaaS\Tests\Stub;

class NullQuery
{
    public function where(...$args): self { return $this; }
    public function update(array $data): int { return 0; }
    public function insert(array $data): bool { return true; }
    public function get() { return collect([]); }
    public function first() { return null; }
}
