<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {


            /**
             * 🧠 UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * 🧠 User relation (optional)
             *
             * For existing users:
             * employee/citizen
             *
             * users.id = UUID
             */
            $table->uuid('user_id')
                ->nullable()
                ->index();


            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();



            /**
             * 🧠 Phone number receiving OTP
             *
             * Important:
             * Used before user exists
             * during registration/login
             */
            $table->string('phone', 20)
                ->index();



            /**
             * 🧠 OTP code
             *
             * Store hashed value in production
             */
            $table->string('code', 255);



            /**
             * 🧠 Purpose of OTP
             *
             * login:
             * citizen login
             *
             * registration:
             * new citizen account
             *
             * verification:
             * verify phone
             */
            $table->enum('type', [

                'login',

                'registration',

                'verification'

            ])
            ->default('login')
            ->index();



            /**
             * 🧠 Expiration time
             */
            $table->timestamp('expires_at')
                ->index();



            /**
             * 🧠 Verification status
             */
            $table->timestamp('verified_at')
                ->nullable();



            /**
             * 🧠 Security control
             *
             * Maximum wrong attempts
             */
            $table->unsignedTinyInteger('attempts')
                ->default(0);



            /**
             * 🧠 Request metadata
             *
             * Useful for security audit
             */
            $table->string('ip_address', 45)
                ->nullable();


            $table->text('user_agent')
                ->nullable();



            /**
             * 🧠 Status
             */
            $table->boolean('is_used')
                ->default(false)
                ->index();



            $table->timestamps();



            /**
             * Prevent multiple active OTPs
             */
            $table->index([
                'phone',
                'type',
                'expires_at'
            ]);

        });
    }



    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }

};