<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $locations = factory(App\Location::class, 30)->create();

        for($i=0; $i<10; $i++) {

            $user_locations = $locations->take(rand(1,5));

            $user = factory(App\User::class)->create(['location' => $user_locations[0]->name]);

            foreach ($user_locations as $user_location) {

                factory(\App\UserLocation::class)->create(
                    ['user_id'=>$user->id, 'location_id'=>$user_location->id]
                );
            }
        }

        factory(App\User::class, 'admin')->create();
    }
}
