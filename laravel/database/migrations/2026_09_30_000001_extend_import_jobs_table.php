<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->string('original_filename')->nullable()->after('filename');
            $table->string('stored_path')->nullable()->after('original_filename');
            $table->char('file_hash', 64)->nullable()->after('stored_path')->index();
            $table->char('dedupe_key', 64)->nullable()->after('file_hash')->unique();
            $table->timestamp('processing_started_at')->nullable()->after('dedupe_key');
            $table->integer('successful_rows')->default(0)->after('processed_rows');
            $table->integer('failed_rows')->default(0)->after('successful_rows');
            $table->string('rejected_path')->nullable()->after('failed_rows');
            $table->timestamp('rejected_expires_at')->nullable()->after('rejected_path');
        });

        Schema::create('import_job_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('row_data');
            $table->json('errors');
            $table->timestamps();

            $table->index('import_job_id');
            $table->unique(['import_job_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_errors');

        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropIndex(['file_hash']);
            $table->dropColumn([
                'original_filename',
                'stored_path',
                'file_hash',
                'dedupe_key',
                'processing_started_at',
                'successful_rows',
                'failed_rows',
                'rejected_path',
                'rejected_expires_at',
            ]);
        });
    }
};
