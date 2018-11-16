<?php

Route::get('/test', function () {

//    \Illuminate\Support\Facades\Artisan::call('dvsa:access');

//    Auth::user()->notify(new \App\Notifications\ReservationMade(Auth::user()));

    $users = \App\User::with(['locations' => function($location) {
//        return $location->where('last_checked', '<', now()->subMinutes(5)->timestamp);
        return $location->where('last_checked', '<', now()->timestamp); // Remove in production
    }])->get();

    $locations = $users->pluck('locations')->flatten()->pluck('name')->unique()->flip();

    return $best_users = (new App\User)->getBest($users, $locations);
});

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', 'HomeController@index')->name('home');
