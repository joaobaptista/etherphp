<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // Capsule the message belongs to (null = loose message). Deleting the capsule deletes its messages.
            $table->string('capsule_id', 64)->nullable()->after('user_id');
            $table->index(['capsule_id', 'created_at']);
            $table->foreign('capsule_id')->references('id')->on('capsules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['capsule_id']);
            $table->dropIndex(['capsule_id', 'created_at']);
            $table->dropColumn('capsule_id');
        });
    }
};
