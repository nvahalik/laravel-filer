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


## Getting Started

To get started, require the project:

    composer require nvahalik/laravel-filer

Once that's done, you'll need to edit the configuration.

## Configuration

The configuration is very similar to other disks:

    'example' => [
        'driver' => 'filer',
        'original_disks' => [
            'test:local'
        ],
        'id' => 'example',
        'disk_strategy' => 'basic', // Placeholder,
        'backing_disks' => [
            'test:local1',
            'test:local2',
        ],
        'visibility' => 'private',
    ],

The `original_disks` is an option if you are migrating from an existing disk or disks to the filer system. Effectively,
this is a fallback so that files which are not found in the local metadata store will be searched for in `original_disks`.
If they are found, their metadata will be imported. If not, the file will be treated as missing. We'll cover doing mass
importing of metadata later on.

`id` is just an internal ID for the metadata store. File duplications are not allowed within the metadata of a single
`id`, for example, but would be allowed for different `id`s.

`disk_strategy` has only a single option currently: `'basic'` but will be pluggable to allow for different
strategies to be added and used. The `basic` strategy simply writes to the first available disk from the list
of provided `backing_disks`.

`backing_disks` allows you to define multiple flysystem disks to use. Want to use multiple S3-compatible adapters? You
can.
