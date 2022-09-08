<?php

namespace Tests\Metadata;

use Nvahalik\Filer\MetadataRepository\Memory;

class MemoryTests extends MetadataBaseTestCase
{
    public function setUp(): void
    {
        $this->repository = new Memory;
        $this->repository->setStorageId('test');
    }

    public function test_it_returns_its_internal_state()
    {
        $this->assertEquals(['test' => []], $this->repository->getData());
    }
}
