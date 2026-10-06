<?php

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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('app_sessions')->nullOnDelete();
            $table->foreignId('error_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('name')->nullable();
            $table->string('app_version', 32);
            $table->string('user_ref')->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type', 'occurred_at']);
            $table->index(['project_id', 'app_version', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
