<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id');
            $table->string('provider');
            $table->string('type');
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | processing | completed | failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            // TODO: How should duplicate events be handled?
            // TODO: Should event_id be unique per provider?
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
