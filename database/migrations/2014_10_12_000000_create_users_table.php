<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->enum('tier', ['free', 'paid', 'premium']);
            $table->boolean('priority')->nullable();
            $table->boolean('booked')->default(false);
            $table->boolean('offer_open')->default(false);
            $table->string('location');
            $table->dateTime('test_date');
            $table->string('preferred_date');
            $table->string('action_code')->default('not_set');
//            $table->string('dl_number')->unique();
//            $table->string('ref_number')->unique();
            // TODO - Make unique in prod
            $table->string('dl_number');
            $table->string('ref_number');
            $table->boolean('details_valid')->default(true);
            $table->string('browser_session_id')->nullable();
            $table->string('email')->unique();
            $table->string('phone_number')->unique();
            $table->enum('contact_preference', ['sms', 'email']);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
