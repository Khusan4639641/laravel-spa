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
        Schema::create('organization_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('content_hash', 64);
            $table->string('author')->nullable();
            $table->date('review_date')->nullable();
            $table->longText('text')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->unique(['organization_id', 'content_hash']);
            $table->unique(['organization_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_reviews');
    }
};
