<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add current status and an immutable history for future transitions. */
    public function up(): void
    {
        /** Existing accounts start with active status and revision zero. */
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedInteger('rider_status_revision')->default(0);
        });

        /** Preserve the actor name as it appeared when the change was made. */
        Schema::create('rider_status_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('admin_name');
            $table->string('status', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at');
            $table->index(['rider_id', 'id']);
        });
    }

    /** Remove the activity and status fields in dependency order. */
    public function down(): void
    {
        Schema::dropIfExists('rider_status_activities');

        /** Remove the two columns introduced by this migration. */
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['suspended_at', 'rider_status_revision']);
        });
    }
};
