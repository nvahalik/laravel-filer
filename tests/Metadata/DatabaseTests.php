<?php

namespace Tests\Metadata;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvahalik\Filer\MetadataRepository\Database;

class DatabaseTests extends MetadataBaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new Database('testbench');
        $this->repository->setStorageId('test');
    }
}
