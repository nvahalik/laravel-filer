<?php

namespace Tests\Metadata;

use Illuminate\Support\Facades\Storage;
use Nvahalik\Filer\MetadataRepository\Json;

class JsonTests extends MetadataBaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->repository = new Json('test.json');
        $this->repository->setStorageId('test');
    }

    public function test_it_loads_existing_data_from_file()
    {
        Storage::fake()->put('test.json', '{
    "test": {
        "example.txt": {
            "path": "example.txt",
            "etag": "ce114e4501d2f4e2dcea3e17b546f339",
            "mimetype": "text\/plain",
            "visibility": "private",
            "size": 14,
            "backing_data": [],
            "created_at": 1618368967,
            "updated_at": 1618368967
        }
    }
}');
        $this->repository = new Json('test.json');
        $this->repository->setStorageId('test');

        $this->assertTrue($this->repository->has('example.txt'));
        $md = $this->repository->getMetadata('example.txt');
        $this->assertIsObject($md);

        $arrayData = $md->toArray();

        $this->assertEquals($arrayData['path'], 'example.txt');
        $this->assertEquals($arrayData['etag'], 'ce114e4501d2f4e2dcea3e17b546f339');
        $this->assertEquals($arrayData['mimetype'], 'text/plain');
        $this->assertEquals($arrayData['visibility'], 'private');
        $this->assertEquals($arrayData['size'], 14);
        $this->assertIsObject($arrayData['backing_data']);
        $this->assertEquals($arrayData['timestamp'], 1618368967);
    }
}
