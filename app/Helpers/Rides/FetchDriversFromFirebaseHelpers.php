<?php

namespace App\Helpers\Rides;

use  Kreait\Firebase\Database;
use Sk\Geohash\Geohash;
use Carbon\Carbon;
use App\Models\Request\RequestMeta;
use Illuminate\Support\Facades\DB;
use App\Models\Request\Request;
use Illuminate\Support\Facades\Log;
use App\Base\Constants\Setting\Settings;
use App\Models\Admin\Driver;
use App\Jobs\Notifications\SendPushNotification;
use App\Models\Request\DriverRejectedRequest;

trait FetchDriversFromFirebaseHelpers
{


    public function __construct(Database $database)
    {
        
        $this->database = $database;

    }

    /**
     * Respond with drivers data.
     * Status code = 200
     *
     * @param mixed|null $data
     * @param string|null $message
     * @return \Illuminate\Http\JsonResponse
     */
    //
    protected function fetchDriversFromFirebase($request_detail)
    {

        if ($request_detail->requestMeta()->exists()) {
             
             return null;   
        }

        $pick_lat = $request_detail->pick_lat;
        $pick_lng = $request_detail->pick_lng;
        $drop_lat = $request_detail->drop_lat;
        $drop_lng = $request_detail->drop_lng;
        $user_detail = $request_detail->userDetail;
        $type_id = $request_detail->zoneType->type_id;

        $driver_search_radius = get_settings('driver_search_radius')?:30;
        
        $radius = kilometer_to_miles($driver_search_radius);

        $calculatable_radius = ($radius/2);

        $calulatable_lat = 0.0144927536231884 * $calculatable_radius;
        $calulatable_long = 0.0181818181818182 * $calculatable_radius;

        $lower_lat = ($pick_lat - $calulatable_lat);
        $lower_long = ($pick_lng - $calulatable_long);

        $higher_lat = ($pick_lat + $calulatable_lat);
        $higher_long = ($pick_lng + $calulatable_long);

        $g = new Geohash();

        $lower_hash = $g->encode($lower_lat,$lower_long, 12);
        $higher_hash = $g->encode($higher_lat,$higher_long, 12);

        $conditional_timestamp = Carbon::now()->subMinutes(7)->timestamp;

        $vehicle_type = $type_id;

        $fire_drivers = $this->database->getReference('drivers')->orderByChild('g')->startAt($lower_hash)->endAt($higher_hash)->getValue();
        
        $firebase_drivers = [];

        $i=-1;
    
        foreach ($fire_drivers as $key => $fire_driver) {
            $i +=1; 
            
            $driver_updated_at = Carbon::createFromTimestamp($fire_driver['updated_at'] / 1000)->timestamp;


        if(array_key_exists('vehicle_type',$fire_driver) && $fire_driver['vehicle_type']==$vehicle_type && $fire_driver['is_active']==1 && $fire_driver['is_available']==1 && $conditional_timestamp < $driver_updated_at){


                $distance = distance_between_two_coordinates($pick_lat,$pick_lng,$fire_driver['l'][0],$fire_driver['l'][1],'K');

                if($distance <= $driver_search_radius){

                    $firebase_drivers[$fire_driver['id']]['distance']= $distance;

                }

            }elseif(array_key_exists('vehicle_types',$fire_driver)  && in_array($vehicle_type, $fire_driver['vehicle_types']) && $fire_driver['is_active']==1 && $fire_driver['is_available']==1 && $conditional_timestamp < $driver_updated_at)
                {


                $distance = distance_between_two_coordinates($pick_lat,$pick_lng,$fire_driver['l'][0],$fire_driver['l'][1],'K');

                if($distance <= $driver_search_radius){

                    $firebase_drivers[$fire_driver['id']]['distance']= $distance;

                }

            }

        }

        asort($firebase_drivers);

        $current_date = Carbon::now();

         if (!empty($firebase_drivers)) {

            $nearest_driver_ids = [];

            $removable_driver_ids=[];

                foreach ($firebase_drivers as $key => $firebase_driver) {
                    
                    $nearest_driver_ids[]=$key;


                $has_enabled_my_route_drivers=Driver::where('id',$key)->where('active', 1)->where('approve', 1)->where('available', 1)->where('enable_my_route_booking',1)->first();


                $route_coordinates=null;

                if($has_enabled_my_route_drivers){

                    //get line string from helper
                    $route_coordinates = get_line_string($pick_lat, $pick_lng, $drop_lat, $drop_lng);

                }       
                        if($has_enabled_my_route_drivers!=null &$route_coordinates!=null){

                            $enabled_route_matched = $nearest_driver->intersects('route_coordinates',$route_coordinates)->first();
                            
                            if(!$enabled_route_matched){

                                $removable_driver_ids[]=$key;
                            }

                            $current_location_of_driver = $nearest_driver->enabledRoutes()->whereDate('created_at',$current_date)->orderBy('created_at','desc')->first();

                            if($current_location_of_driver){

                            $distance_between_current_location_to_drop = distance_between_two_coordinates($current_location_of_driver->current_lat, $current_location_of_driver->current_lng, $drop_lat, $drop_lng,'K');

                            $distance_between_current_location_to_my_route = distance_between_two_coordinates($current_location_of_driver->current_lat, $current_location_of_driver->current_lng, $nearest_driver->my_route_lat, $nearest_driver->my_route_lng,'K');

                            // Difference between both of above values

                            $difference = $distance_between_current_location_to_drop - $distance_between_current_location_to_my_route;

                            $difference=$difference < 0 ? (-1) * $difference : $difference;

                            if($difference>5){

                                $removable_driver_ids[]=$key;

                            }
    
                            }
                            
                        }


                }

            $rejected_driver_ids = DriverRejectedRequest::where('request_id',$request_detail->id)->pluck('driver_id')->toArray();

            $nearest_driver_ids = array_diff($nearest_driver_ids,$removable_driver_ids);
            $nearest_driver_ids = array_diff($nearest_driver_ids,$rejected_driver_ids);

            if(count($nearest_driver_ids)==0){

                return null; 

                }

            $driver_search_radius = get_settings('driver_search_radius')?:30;

                $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";
                // Get Drivers who are all going to accept or reject the some request that nears the user's current location.

                $meta_drivers = RequestMeta::whereHas('request.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
                    $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
                ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
                })->pluck('driver_id')->toArray();


                $nearest_drivers = Driver::where('active', 1)->where('approve', 1)->where('available', 1)->whereIn('id', $nearest_driver_ids)->whereNotIn('id', $meta_drivers)->orderByRaw(DB::raw("FIELD(id, " . implode(',', $nearest_driver_ids) . ")"))->limit(10)->get();

                if ($nearest_drivers->count()==0) {
                
                    return null; 
                }
        //Create Meta & Send Ride Request to the Nearest Drivers
        $selected_drivers = [];
        $i = 0;
        foreach ($nearest_drivers as $driver_key => $driver) {

            // $selected_drivers[$i]["request_id"] = $request_detail->id;
            foreach ($firebase_drivers as $key => $firebase_driver) {

                    if($driver->id==$key){
                        $selected_drivers[$i]["distance_to_pickup"] = $firebase_driver['distance'];
                    }
            }
            
            $selected_drivers[$i]["user_id"] = $user_detail->id;
            $selected_drivers[$i]["driver_id"] = $driver->id;
            $selected_drivers[$i]["active"] = 1;
            $selected_drivers[$i]["assign_method"] = 1;
            $selected_drivers[$i]["created_at"] = date('Y-m-d H:i:s');
            $selected_drivers[$i]["updated_at"] = date('Y-m-d H:i:s');


        // Add Driver into Firebase Request Meta
        $this->database->getReference('request-meta/'.$request_detail->id)->set(['driver_id'=>$driver->id,'request_id'=>$request_detail->id,'user_id'=>$request_detail->user_id,'active'=>1,'updated_at'=> Database::SERVER_TIMESTAMP]);

        
        $driver = Driver::find($driver->id);

        $notifable_driver = $driver->user;

        $title = trans('push_notifications.new_request_title',[],$notifable_driver->lang);
        $body = trans('push_notifications.new_request_body',[],$notifable_driver->lang);

        dispatch(new SendPushNotification($notifable_driver,$title,$body));


        if(get_settings('trip_dispatch_type')==0){
            $selected_drivers[$i]["active"] = 1;
        }else{
            if($driver_key==0){
                break;                
            }
        }

            $i++;
        }

       
        foreach ($selected_drivers as $key => $selected_driver) {
            $request_detail->requestMeta()->create($selected_driver);
        }

        return "success";

            
        } else {

            return null;

        }

    }

 
    
}
