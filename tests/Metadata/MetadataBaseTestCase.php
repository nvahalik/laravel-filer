<?php

namespace Tests\Metadata;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;
use Nvahalik\Filer\MetadataRepository\Memory;
use Tests\TestCase;

class MetadataBaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected MetadataRepository $repository;

    public function setUp(): void
    {
        parent::setUp();
        $this->repository = new Memory();
        $this->repository->setStorageId('test');
    }

    public function test_it_returns_false_if_metadata_does_not_exist()
    {
        $this->assertNull($this->repository->getMetadata('does/not/exist.txt'));
    }

    public function test_it_returns_false_if_size_does_not_exist()
    {
        $this->assertFalse($this->repository->getSize('does/not/exist.txt'));
    }

    public function test_it_returns_false_if_timestamp_does_not_exist()
    {
        $this->assertFalse($this->repository->getTimestamp('does/not/exist.txt'));
    }

    public function test_it_returns_false_if_visibility_does_not_exist()
    {
        $this->assertFalse($this->repository->getVisibility('does/not/exist.txt'));
    }

    public function test_it_returns_false_if_mimetype_does_not_exist()
    {
        $this->assertFalse($this->repository->getMimetype('does/not/exist.txt'));
    }

    public function test_it_stores_metadata()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertNotFalse($this->repository->getMetadata('example.txt'));
    }

    public function test_it_returns_data_if_metadata_does_exists()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertNotFalse($this->repository->getMetadata('example.txt'));
    }

    public function test_it_returns_false_if_size_exists()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertEquals(14, $this->repository->getSize('example.txt'));
    }

    public function test_it_returns_false_if_timestamp_exists()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertNotFalse($this->repository->getTimestamp('example.txt'));
    }

    public function test_it_returns_false_if_visibility_exists()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertEquals('private', $this->repository->getVisibility('example.txt'));
    }

    public function test_it_returns_false_if_mimetype_exists()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertEquals('text/plain', $this->repository->getMimetype('example.txt'));
    }

    public function test_it_renames_a_file()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertTrue($this->repository->has('example.txt'));
        $this->assertFalse($this->repository->has('example2.txt'));

        $this->repository->rename('example.txt', 'example2.txt');

        $this->assertFalse($this->repository->has('example.txt'));
        $this->assertTrue($this->repository->has('example2.txt'));
    }

    public function test_it_sets_the_visibility_of_a_file()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertEquals('private', $this->repository->getVisibility('example.txt'));

        $this->repository->setVisibility('example.txt', 'public');

        $this->assertEquals('public', $this->repository->getVisibility('example.txt'));
    }

    public function test_it_lists_a_directory()
    {
        $contents = $this->repository->listContents();

        $this->assertCount(0, $contents);

        $this->repository->record(Metadata::generate('example.txt', 'abc'));
        $this->repository->record(Metadata::generate('abc/example.txt', 'abc'));
        $this->repository->record(Metadata::generate('abc/example2.txt', 'abc'));
        $this->repository->record(Metadata::generate('abc/def/example.txt', 'abc'));
        $this->repository->record(Metadata::generate('abc/def/example2.txt', 'abc'));
        $this->repository->record(Metadata::generate('def/example.txt', 'abc'));
        $this->repository->record(Metadata::generate('def/example2.txt', 'abc'));
        $this->repository->record(Metadata::generate('def/abc/example.txt', 'abc'));
        $this->repository->record(Metadata::generate('def/abc/example2.txt', 'abc'));

        $contents = $this->repository->listContents();

        $this->assertCount(1, $contents);

        $contents = $this->repository->listContents('', true);

        $this->assertCount(9, $contents);

        $contents = $this->repository->listContents('abc/');

        $this->assertCount(2, $contents);

        $contents = $this->repository->listContents('abc/', true);

        $this->assertCount(4, $contents);
    }

    public function test_it_deletes_a_file()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $this->assertTrue($this->repository->has('example.txt'));

        $this->repository->delete('example.txt');

        $this->assertFalse($this->repository->has('example.txt'));
    }

    public function test_it_adds_backing_data()
    {
        $this->repository->record(Metadata::generate('example.txt', 'This is a test'));

        $md = $this->repository->getMetadata('example.txt');

        $this->assertIsObject($md->backingData);
        $this->assertCount(0, $md->backingData->toArray());

        $this->repository->setBackingData('example.txt', BackingData::diskAndPath('temp', 'example.txt'));

        $md = $this->repository->getMetadata('example.txt');

        $this->assertIsObject($md->backingData);
        $this->assertCount(1, $md->backingData->toArray());
    }
}
