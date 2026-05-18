<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('status')->default('pending'); // pending | processing | completed | failed
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // TODO: How can progress be tracked per row?
            // TODO: How should row-level errors be stored?
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
