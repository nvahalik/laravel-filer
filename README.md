# Laravel Filer

This project was started to scratch my itch on our growing Laravel site:

* **Metadata for all files is stored in a local repository** - Supported backing systems are `json`, `database`, and
  `memory` (for testing). This is designed to speed up certain operations which normally call out to the remote
  filesystems.
* **Handles fallback to the original disk** - If you provide an "original disk" list, this adapter will attempt to
  import data from those original disks into the metadata repository.
* **Pluggable Strategies** - While the current version ships with a single strategy, you can replace the `Basic`
  implementation which allows for 1 + async, mirror, or other interactions with backing storage adapters.
* **Manage data + files** - Coming soon: query and manage the metadata to do things like:
    * Find files stored on a single service and mirror them
    * Migrate files between stores while still maintaining continuity
* **Abstract data from metadata** - Planning at some point on allow things like deduplication and copy-on-write to make
  copies, renames, and deletions work better.

# Getting Started

To get started, require the project:

    composer require nvahalik/laravel-filer

Once that's done, you'll need to edit the filer config file and then update your filesystem configuration.

# Config File

By default, the metadata is stored in a JSON file. You can edit `config/filer.php` to change the default storage
mechanism from `json` to `database` or `memory`. Note that memory is really a null adapter. The JSON adapter wraps
memory and just serializes and saves it after each operation.

# Filesystem Configuration

The configuration is very similar to other disks:

    'example' => [
        'driver' => 'filer',
        'original_disks' => [
            'test:s3-original',
        ],
        'id' => 'test',
        'disk_strategy' => 'basic',
        'backing_disks' => [
            'test:s3-new',
            'test:s3-original',
        ],
        'visibility' => 'private',
    ],

The `original_disks` is an option if you are migrating from an existing disk or disks to the filer system. Effectively,
this is a fallback so that files which are not found in the local metadata store will be searched for
in `original_disks`. If they are found, their metadata will be imported. If not, the file will be treated as missing.
We'll cover doing mass importing of metadata later on.

**Note: that this will slow the filesystem down until the cache is filled. Once the cache is loaded, you can remove
these original_disks and those extra operations looking for files will be eliminated.**

**Note 2: files which are truly missing do not get cached. Therefore, if a file is missing, and you repeatedly attempt
to assert its existence, it will search over and over again. This could be improved by caching the results or likewise
having some sort of `missing-files` cache.**

`id` is just an internal ID for the metadata store. File duplications are not allowed within the metadata of a single
`id`, for example, but would be allowed for different `id`s.

`disk_strategy` has only a single option currently: `'basic'` but will be pluggable to allow for different strategies to
be added and used. The `basic` strategy simply writes to the first available disk from the list of
provided `backing_disks`.

`backing_disks` allows you to define multiple flysystem disks to use. Want to use multiple S3-compatible adapters? You
can. Note that for the basic adapter, the order of the disks determines the order in which they are tried.

## A couple of examples

Given the configuration above, if the following code is run:

```
Storage::disk('example')->has('does/not/exist.txt');
```

1. The existing metadata repo will be searched.
2. Then, the single 'original_disks' will be searched.
3. Finally, the operation will fail.

```
Storage::disk('example')->put('does/not/exist.txt', 'content');
```

1. The existing metadata repo will be searched.
2. Then, the single 'original_disks' will be searched.
3. Then, a write will be attempted on `test:s3-new`.
4. If that fails, then a write will be attempted on `test:s3-original`.
5. If any of the writes succeeds, that adapter's backing information will be returned and the entries metadata updated.
6. If any of them fails, then false will be returned and the operation will have failed.

# Importing Metadata

If you already have a ton of files on S3, you can use the `filer:import-s3-metadata` command to import that data into
your metadata repository:

```bash
# Grab the existing contents.
s3cmd ls s3://bucket-name -rl > s3output.txt

# Import that data into the "example" storageId.
php artisan filer:import-s3-metadata example s3output.txt
```

The importer uses `File::lines()` to load its data, and therefore should not consume a lot of memory. Additionally, it
will look at the bucket name in the URL which is present in the output and attempt to find that within your existing
filesystems config.

## Visibility

By default, it will grab this from the filesystem configuration. If none is found nor provided with `--visibility`, it
will default to `private`.

## Filename stripping

You can strip a string from the filenames by specifying the `--strip` option.

## Disk

If you need to specify the disk directly or want to otherwise override it, just pass it in with `--disk`. This is not
checked, so don't mess it up.

## Example

```bash
php artisan filer:import-s3-metadata example s3output.txt --disk=some-disk --visibility=public --strip=prefix-dir/ 
```

The above command would strip `prefix-dir/` from the imported URLs, set their visibility to public, and mark their
default backing-disk to `some-disk`. 
