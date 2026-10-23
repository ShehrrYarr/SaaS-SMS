<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a notice's in-app notifications went out, so a scheduled notice is
 * announced exactly once when it goes live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('expires_at');
            $table->index(['published_at', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropIndex(['published_at', 'notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
