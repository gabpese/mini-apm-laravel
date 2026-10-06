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
        Schema::create('app_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('user_ref')->nullable();
            $table->string('app_version', 32);
            $table->string('os')->nullable();
            $table->unsignedInteger('ram_mb')->nullable();
            $table->string('gpu')->nullable();
            $table->timestamp('started_at');
            $table->timestamps();

            $table->index(['project_id', 'app_version']);
            $table->index(['project_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_sessions');
    }
};
