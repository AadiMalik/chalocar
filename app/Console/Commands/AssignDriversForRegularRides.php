<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Driver;
use App\Jobs\NotifyViaSocket;
use App\Models\Request\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Request\RequestMeta;
use App\Jobs\NoDriverFoundNotifyJob;
use App\Base\Constants\Masters\PushEnums;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Transformers\Requests\CronTripRequestTransformer;
use App\Models\Request\DriverRejectedRequest;
use Sk\Geohash\Geohash;
use Kreait\Firebase\Database;
//use Kreait\Firebase\Contract\Database;
use Illuminate\Support\Facades\Log;
use App\Jobs\Notifications\SendPushNotification;
use Illuminate\Support\Collection;
use App\Helpers\Rides\FetchDriversFromFirebaseHelpers;



class AssignDriversForRegularRides extends Command
{
    use FetchDriversFromFirebaseHelpers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assign_drivers:for_regular_rides';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign Drivers for regular rides';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Database $database)
    {
        $this->database = $database;
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $current_time = Carbon::now()->format('Y-m-d H:i:s');
        $sub_5_min = Carbon::now()->subMinutes(20)->format('Y-m-d H:i:s');
        // DB::enableQueryLog();
        $requests = Request::where('is_later', 0)
                    ->where('created_at', '<=', $current_time)
                    ->where('created_at', '>', $sub_5_min)
                    ->where('is_bid_ride',0)
                    ->where('is_completed', 0)->where('is_cancelled', 0)->where('is_driver_started', 0)->get();
        // dd($current_time);

        // dd($sub_5_min);

        if ($requests->count()==0) {
            return $this->info('no-regular-rides-found');
        }

        // dd(DB::getQueryLog());
        foreach ($requests as $key => $request) {

            $this->fetchDriversFromFirebase($request);

        }

        $this->info('success');
    }
}
