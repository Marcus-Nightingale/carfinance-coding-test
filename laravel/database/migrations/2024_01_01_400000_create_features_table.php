<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('feature_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['feature_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_user');
        Schema::dropIfExists('features');
    }
};
