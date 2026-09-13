<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parsing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('reviews_found')->default(0);
            $table->unsignedInteger('reviews_saved')->default(0);
            $table->string('current_step')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parsing_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count');
            $table->unsignedInteger('reviews_count');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'id']);
            $table->unique('parsing_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
        Schema::dropIfExists('parsing_runs');
    }
};
