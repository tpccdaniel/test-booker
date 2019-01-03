<?php

namespace App\Jobs;

use App\User;
use App\Proxy;
use Carbon\Carbon;
use App\Browser\Browser;
use App\Jobs\MakeReservation;
use Illuminate\Bus\Queueable;
use App\Modules\InteractsWithDVSA;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ScrapeDVSA implements ShouldQueue 
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels,
        InteractsWithDVSA;

    public static $stage = 'Start';
    public $tries = 3;
    public $timeout = 240;
    private $user;
    private $toNotify;

    /** @var Proxy */
    private $proxy;

    /** @var \Tpccdaniel\DuskSecure\Browser */
    private $window;

    /**
     * Create a new job instance.
     *
     * @param User $user
     */
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->toNotify = collect();
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Illuminate\Contracts\Redis\LimiterTimeoutException
     */
    public function handle()
    {
        Redis::connection('default')->funnel('DVSA')->limit(env("PROXY_LIMIT"))->then(function () {
        Redis::connection('default')->funnel($this->user->dl_number)->limit(1)->then(function() {

            (new Browser)->browse(function ($window, $proxy) {
                
                $this->proxy = $proxy;
                $this->window = $window;        
                
                $this->getToCalendar();
                
                $this->loopLocations();
                
                if ($book = false) {
                    dispatch(new MakeReservation($this->user));
                } 
                
                if ($book = true) {
                    $this->window->quit();
                }
            });

            $this->proxy->update(['completed' => $this->proxy->completed + 1, 'fails' => 0]);
            
            // Make reservation events.
            foreach ($this->to_notify as $item) {

                dispatch(new MakeReservation($item['user'], $item['slot']));
            }
            
        }, function () {

            \Log::info('Releasing user');
            return $this->release(30);
        });
        }, function () {

            \Log::info('Releasing job');
            return $this->release(30);
        });        
    }

    protected function failed()
    {
        ScrapeDVSA::dispatch($this->user)->onConnection('redis');
    }
}
