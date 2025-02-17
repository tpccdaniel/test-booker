<?php

namespace App\Console\Commands;

use App\Location;
use App\Notifications\ReservationMade;
use App\User;
use Carbon\Carbon;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Console\Command;
use App\Browser\Browser;
use Illuminate\Support\Facades\Log;

/**
 * Class DVSACheck
 * @package App\Console\Commands
 */
class DVSACheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dvsa:access {--getslot} {--book} {user?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks dvsa website for changes for earlier appointments';

    /**
     * @var \Tpccdaniel\DuskSecure\Browser
     */
    protected $browser;

    /**
     * @var \Tpccdaniel\DuskSecure\Browser
     */
    protected $window;

    protected $users;

    /**
     * Create a new command instance.
     *
     * @param Browser $browser
     */
    public function __construct(Browser $browser)
    {
        parent::__construct();

        $this->browser = $browser;

        $test = new Browser;

        return 'test';
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Throwable
     */
    public function handle()
    {
        $data['username'] = env('DL_NUMBER');
        $data['password'] = env('REF_NUMBER');

        $user = \Auth::user();
        $locations = Location::all();
        $this->users = User::all();

        $this->browser->browse(function ($browser) use ($data, $user, $locations) {

            $this->window = $browser;

            $incapsula_cookies = array_where(array_pluck($this->window->getCookies(), 'name'), function ($cookie) {
                return str_contains($cookie, 'incap');
            });

            if ($incapsula_cookies) {
                foreach ($incapsula_cookies as $cookie) {
                    $this->window->deleteCookie($cookie);
                }
            }

            // Login
            $this->window->visit('https://www.gov.uk/change-driving-test');
            $this->window->screenshot('Home');
            $this->window->clickLink('Start now');
            $this->window->screenshot('Login Page');
            // Handle captcha
            if ($this->window->captcha()) {
                $this->window->pause(10000)->screenshot("CAPTCHA-".now()->format('h.m.i'));
                Log::info($this->window->captcha());
                return;
            }

            Log::info($this->window->html('body')[0]);

            $this->window->type('#driving-licence-number', $data['username']);
            $this->window->type('#application-reference-number', $data['password']);
            $this->window->click('#booking-login');

            $this->window->pause(rand(250, 1000));

            $this->window->click('#date-time-change')
                         ->click('#test-choice-earliest')
                         ->pause(rand(250, 1000))
                         ->click('#driving-licence-submit')
                         ->pause(rand(250, 1000));

//            $all_slots = json_decode(file_get_contents(base_path('data/all_slots.json')), true);
            $to_notify = collect();
            foreach ($locations as $location) {
                $this->window->pause(rand(250,1000))
                             ->click('#change-test-centre')
                             ->pause(rand(250,1000))
                             ->type('#test-centres-input', $location->name)
                             ->pause(rand(250,1000))
                             ->click('#test-centres-submit')
                             ->pause(rand(250,1000))
                             ->clickLink(ucfirst($location->name));

                $slots = $this->scrapeSlots($location->name);
                $location->update(['last_checked' => now()->timestamp]);
                $to_notify->push($this->getScores($slots, $location));
            }

            $this->line('Sending emails...');

            $list = $to_notify->collapse()->groupBy('user.id');

            foreach ($list as $item) {
                $item = $item->sortByDesc('date')->sortByDesc('user.points')[0];
                $user = $this->users->find($item['user']['id']);
                $user->notify(new ReservationMade($user, $item));
            }

            $this->window->quit();
        });
    }

    /**
     * @param $location
     * @return array
     */
    public function scrapeSlots($location)
    {
        $slots = [];
        $slots[$location] = [];
        foreach (array_slice($this->window->elements('.SlotPicker-slot-label'), 10) as $element) { /** @var $element RemoteWebElement */
            $string = $element->findElement(
                WebDriverBy::className('SlotPicker-slot')
            )->getAttribute('data-datetime-label');

            $slot = Carbon::parse($string)->toDateTimeString();

            array_push($slots[$location], $slot);
        }

        return array_values($slots);
    }

    /**
     * @param $slots
     * @param $location
     * @return \Illuminate\Support\Collection
     */
    public function getScores($slots, $location)
    {
        $users = $location->users->sortByDesc('priority');
        $slots = $this->removeSlotsAfter($slots,
            Carbon::parse($users->pluck('test_date')->sort()->last())
        );

        $user_points = [];
        foreach ($slots[0] as $slot) {
            $user_points[$slot] = [];
            foreach ($users as $user) {
                $id = $user->id;
                $user_points[$slot][$id] = 0;
                if (Carbon::parse($slot)->greaterThan($user->test_date))
                    continue;
                if ($user->location == $location->name)
                    $user_points[$slot][$id] += 2;
                if ($user->priority)
                    $user_points[$slot][$id] += 1;
            }
        }

        if (!$user_points) {
            $this->window->quit();
            return null;
        }

        $eligible_candidates = $this->sliceEligibleCandidates($user_points);

        $slots = $eligible_candidates->map(function ($ids, $date) use ($eligible_candidates, $location) {
            $users = collect($ids)->filter()->sort()->map(function ($value, $key) {
                return ['id' => $key, 'points' => $value];
            })->values();

            $item = ['date' => $date,
                    'location' => $location->name,
                    'user' => $users[$eligible_candidates->keys()->search($date)]];

            return $item;
        })->values();

        return $slots;
    }

    /**
     * @param $user_points
     * @return \Illuminate\Support\Collection
     */
    public function sliceEligibleCandidates($user_points)
    {
        $eligible_candidates = array_where(array_first($user_points), function ($value) {
            return $value != 0;
        });

        return collect(array_slice($user_points, 0, count($eligible_candidates)));
    }

    /**
     * @param $slots
     * @param $latest_test_date Carbon
     * @return array
     */
    public function removeSlotsAfter($slots, $latest_test_date)
    {
        // Loop though slots until get to the point then break and slice array with index
        foreach ($slots as $index => $slot) {
            if ($latest_test_date->lessThanOrEqualTo($slot)) {
                return array_slice($slots, 0, $index);
            }
        }
        return $slots;
    }
}