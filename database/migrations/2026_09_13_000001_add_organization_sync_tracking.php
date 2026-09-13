<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->renameColumn('yandex_external_id', 'external_id');
            $table->renameColumn('scrape_status', 'status');
            $table->renameColumn('scrape_error', 'last_error');
            $table->renameColumn('last_scraped_at', 'last_successful_sync_at');
        });
        // Failed legacy scrapes also wrote last_scraped_at; do not present them as successes.
        DB::table('organizations')->where('status', '!=', 'success')->update(['last_successful_sync_at' => null]);
        DB::table('organizations')->where('status', 'success')->update(['status' => 'ready']);
        DB::table('organizations')->whereIn('status', ['pending', 'processing'])->update(['status' => 'idle']);
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_sync_finished_at')->nullable();
            $table->index('external_id');
            $table->index('status');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });
        DB::table('users')->orderBy('id')->each(function ($user): void {
            DB::table('users')->where('id', $user->id)->update([
                'current_organization_id' => DB::table('organizations')->where('user_id', $user->id)->max('id'),
            ]);
        });
        Schema::table('organization_reviews', function (Blueprint $table): void {
            $table->index('content_hash');
            $table->index('external_id');
            $table->index(['organization_id', 'review_date', 'id'], 'reviews_pagination_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_organization_id'));
        Schema::table('organization_reviews', function (Blueprint $table): void {
            $table->dropIndex(['content_hash']);
            $table->dropIndex(['external_id']);
            $table->dropIndex('reviews_pagination_index');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['external_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['last_sync_started_at', 'last_sync_finished_at']);
            $table->renameColumn('external_id', 'yandex_external_id');
            $table->renameColumn('status', 'scrape_status');
            $table->renameColumn('last_error', 'scrape_error');
            $table->renameColumn('last_successful_sync_at', 'last_scraped_at');
        });
        DB::table('organizations')->where('scrape_status', 'ready')->update(['scrape_status' => 'success']);
    }
};
