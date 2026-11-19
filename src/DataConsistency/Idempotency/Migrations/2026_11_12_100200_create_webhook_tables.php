<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Receiver side: which message ids has each consumer already handled?
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->id();
            $table->string('consumer');
            $table->string('message_id');
            $table->timestamp('processed_at');

            // The unique index is the deduplication mechanism.
            $table->unique(['consumer', 'message_id']);
        });

        // Sender side: webhooks we still have to deliver (and retry).
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('url');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | delivered | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('processed_messages');
    }
};
