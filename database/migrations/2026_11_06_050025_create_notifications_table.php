<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Adds only private inbox storage; existing accounts and business records are untouched. */
    public function up(): void
    {
        Schema::create('notifications', /** Support owner-scoped unread counts and timelines. */ function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_owner_unread_date');
        });
    }

    /** Reverses this feature's new table without touching delivery or payment history. */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
