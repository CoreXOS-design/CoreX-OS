<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('messaging_opt_out_at')->nullable();
            $table->string('messaging_opt_out_reason', 255)->nullable();
            $table->unsignedBigInteger('messaging_opt_out_recorded_by_user_id')->nullable();

            $table->foreign('messaging_opt_out_recorded_by_user_id', 'contacts_msg_optout_recorded_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index('messaging_opt_out_at', 'contacts_messaging_opt_out_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign('contacts_msg_optout_recorded_by_fk');
            $table->dropIndex('contacts_messaging_opt_out_at_idx');
            $table->dropColumn([
                'messaging_opt_out_recorded_by_user_id',
                'messaging_opt_out_reason',
                'messaging_opt_out_at',
            ]);
        });
    }
};
