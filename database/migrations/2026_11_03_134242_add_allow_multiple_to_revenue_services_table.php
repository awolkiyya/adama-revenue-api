
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
        Schema::table('revenue_services', function (Blueprint $table) {
            $table->boolean('allow_multiple')
                ->default(false)
                ->after('collection_mode')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revenue_services', function (Blueprint $table) {
            $table->dropIndex(['allow_multiple']);
            $table->dropColumn('allow_multiple');
        });
    }
};
