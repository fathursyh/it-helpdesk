<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('division')->default(Division::Other->value)->index();
            $table->string('category')->default(TicketCategory::Other->value)->index();
            $table->string('priority')->default(TicketPriority::Medium->value)->index();
            $table->string('status')->default(TicketStatus::Open->value)->index();
            $table->string('title');
            $table->text('description');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_internal')->default(false)->index();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained('ticket_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename');
            $table->string('file_path');
            $table->unsignedInteger('file_size');
            $table->string('mime_type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
