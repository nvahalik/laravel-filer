<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilerTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('filer.database.connection'))->create('filer_metadata', function (Blueprint $table) {
            $table->uuid('id')->comment('Internal ID.');
            $table->string('disk')->comment('The disk ID of the file.');
            $table->string('path')->comment('The path of the file "on-disk".');
            $table->unsignedInteger('size')->comment('The size of the file.');
            $table->string('mimetype')->default('application/octet-stream');
            $table->string('etag')->nullable()->comment('The Etag for the file.');
            $table->enum('visibility', ['public', 'private'])->comment('Visibiilty of the file.');
            $table->json('backing_data')->comment('The information about where the file is stored and how.');
            $table->timestamp('timestamp')->useCurrent();
            $table->unique([
                'disk', 'path',
            ]);
            $table->primary('id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('filer.database.connection'))->dropIfExists('filer_metadata');
    }
}
