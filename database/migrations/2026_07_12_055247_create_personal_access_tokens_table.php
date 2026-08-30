<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {


            /**
             * Token ID
             */
            $table->id();



            /**
             * UUID user relation
             *
             * users.id = UUID
             *
             * Important:
             * Use uuidMorphs because User model uses UUID
             */
            $table->uuidMorphs('tokenable');



            /**
             * Token name
             *
             * Example:
             * web
             * mobile
             * government-system
             */
            $table->string('name');



            /**
             * Hashed token
             */
            $table->string('token', 64)
                ->unique();



            /**
             * Permissions
             *
             * Example:
             * ["*"]
             */
            $table->text('abilities')
                ->nullable();



            /**
             * Usage tracking
             */
            $table->timestamp('last_used_at')
                ->nullable();



            /**
             * Expiration
             */
            $table->timestamp('expires_at')
                ->nullable()
                ->index();



            $table->timestamps();

        });
    }



    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }

};