<?php

namespace App\Transformers\User;

use Carbon\Carbon;
use App\Models\Admin\Zone;
use App\Models\Admin\Promo;
use App\Models\Admin\Driver;
use App\Models\Admin\ZoneType;
use App\Models\Admin\PromoUser;
use App\Transformers\Transformer;
use App\Models\Admin\ZoneSurgePrice;
use App\Models\Master\DistanceMatrix;
use Illuminate\Support\Facades\Redis;
use App\Helpers\Exception\ExceptionHelpers;
use App\Base\Constants\Masters\EtaConstants;
use App\Base\Constants\Masters\zoneRideType;
use App\Transformers\Access\RoleTransformer;
use Illuminate\Support\Facades\Log;
use App\Base\Constants\Auth\Role;

class EtaTransformer extends Transformer
{
    use ExceptionHelpers;
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected array $availableIncludes = [];

    /**
     * A Fractal transformer.
     *
     * @return array
     */
    public function transform(ZoneType $zone_type)
    {
        $pick_lat = request()->pick_lat;
        $pick_lng = request()->pick_lng;
        $drop_lat = request()->drop_lat;
        $drop_lng = request()->drop_lng;

        $response = [
            'zone_type_id' => $zone_type->id,
            'name' => $zone_type->vehicleType->name,
            'description' => $zone_type->vehicleType->description,
            'short_description' => $zone_type->vehicleType->short_description,
            'supported_vehicles' => $zone_type->vehicleType->supported_vehicles,
            'payment_type' => $zone_type->payment_type,
            'is_default' => $zone_type->zone->default_vehicle_type == $zone_type->type_id,
        ];

        if (!request()->has('vehicle_type')) {
            $response['icon'] = $zone_type->icon;
            $response['type_id'] = $zone_type->type_id;
        }

        // Ride type
        $ride_type = request()->ride_type == zoneRideType::RIDENOW ? zoneRideType::RIDENOW : zoneRideType::RIDELATER;

        // Promo code validation
        $coupon_detail = null;
        if (request()->has('promo_code') && request()->input('promo_code')) {
            $coupon_detail = $this->validate_promo_code($zone_type->zone->service_location_id);
        }

        $type_prices = $zone_type->zoneTypePrice()->where('price_type', $ride_type)->first();

        // Distance calculation including stops
        $distance_in_unit = 0;
        $previous_lat = $pick_lat;
        $previous_lng = $pick_lng;

        if (request()->has('stops') && request()->stops) {
            $requested_stops = json_decode(request()->stops);
            foreach ($requested_stops as $stop) {
                $segment_distance = distance_between_two_coordinates(
                    $previous_lat,
                    $previous_lng,
                    $stop->latitude,
                    $stop->longitude,
                    'K'
                );

                $distance_in_unit += ($zone_type->zone->unit == 2)
                    ? kilometer_to_miles($segment_distance)
                    : $segment_distance;

                $previous_lat = $stop->latitude;
                $previous_lng = $stop->longitude;
            }
        }

        // Last segment to final dropoff
        if ($drop_lat && $drop_lng) {
            $segment_distance = distance_between_two_coordinates(
                $previous_lat,
                $previous_lng,
                $drop_lat,
                $drop_lng,
                'K'
            );

            $distance_in_unit += ($zone_type->zone->unit == 2)
                ? kilometer_to_miles($segment_distance)
                : $segment_distance;
        }

        // Dropoff time (demo env)
        $dropoff_time_in_seconds = 0;
        if (env('APP_FOR') == 'demo') {
            if ($distance_in_unit < 2) {
                $dropoff_time_in_seconds = 180;
            } elseif ($distance_in_unit < 5) {
                $dropoff_time_in_seconds = 480;
            } else {
                $dropoff_time_in_seconds = 600;
            }
        } else {
            if ($drop_lat && $drop_lng) {
                $previous_pickup_dropoff = $this->db_query_previous_pickup_dropoff($pick_lat, $pick_lng, $drop_lat, $drop_lng);
                $place_details = json_decode($previous_pickup_dropoff->json_result);
                $dropoff_time_in_seconds = get_duration_value_from_distance_matrix($place_details);
            }
        }

        // User wallet
        $user_balance = 0;
        $user = auth()->user();
        if ($user && !$user->hasRole(Role::DRIVER) && !$user->hasRole(Role::DISPATCHER)) {
            $user_balance = $user->userWallet->amount_balance ?? 0;
        }
        $response['user_wallet_balance'] = $user_balance;

        // Unit words
        $unit_in_words = $zone_type->zone->unit == 1 ? 'KM' : 'MILES';
        $translated_unit_in_words = $unit_in_words;

        // Calculate fare
        $ride = $this->calculateRideFares($distance_in_unit, $dropoff_time_in_seconds, $zone_type, $type_prices, $coupon_detail);

        // Driver estimation
        $near_driver_status = 0;
        $driver_arival_estimation = "--";
        if (request()->has('drivers')) {
            $driver_data_with_distance = [];
            $driver_distance = [];
            foreach (json_decode(request()->drivers) as $driver) {
                $distance = self::calculate_distance($pick_lat, $pick_lng, $driver->driver_lat, $driver->driver_lng, 'K');
                $driver_data_with_distance[] = (object)[
                    'id' => $driver->driver_id,
                    'lat' => $driver->driver_lat,
                    'lng' => $driver->driver_lng,
                    'distance' => $distance
                ];
                $driver_distance[] = $distance;
            }

            if (!empty($driver_distance)) {
                $min_distance = min($driver_distance);
                foreach ($driver_data_with_distance as $d) {
                    if ($d->distance == $min_distance) {
                        $near_driver_status = 1;
                        $driver_arival_estimation = $ride->pickup_duration != 0 ? "{$ride->pickup_duration} min" : "1 min";
                        break;
                    }
                }
            }
        }

        // Fill response
        $response = array_merge($response, [
            'has_discount' => $ride->discount_amount > 0,
            'discounted_totel' => $ride->discounted_total_price,
            'discount_total_tax_amount' => $ride->discount_total_tax_amount ?? 0,
            'promocode_id' => $coupon_detail->id ?? null,
            'discount_amount' => $ride->discount_amount,
            'distance' => $ride->distance,
            'time' => $ride->duration,
            'base_distance' => $ride->base_distance,
            'base_price' => $ride->base_price,
            'price_per_distance' => $ride->price_per_distance,
            'price_per_time' => $ride->price_per_time,
            'distance_price' => $ride->distance_price,
            'time_price' => $ride->time_price,
            'ride_fare' => $ride->subtotal_price,
            'tax_amount' => $ride->tax_amount,
            'tax' => $ride->tax_percent,
            'total' => $ride->total_price,
            'approximate_value' => 1,
            'min_amount' => $ride->total_price,
            'max_amount' => ($ride->total_price * 1.05),
            'currency' => $zone_type->zone->serviceLocation->currency_symbol,
            'currency_name' => $zone_type->zone->serviceLocation->currency_code,
            'type_name' => $zone_type->vehicleType->name,
            'unit' => $zone_type->zone->unit,
            'unit_in_words_without_lang' => $unit_in_words,
            'unit_in_words' => $translated_unit_in_words,
            'driver_arival_estimation' => $driver_arival_estimation
        ]);

        return $response;
    }


    public function calculate_distance($lat1, $lon1, $lat2, $lon2, $unit)
    {
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);

            if ($unit == "K") {
                return ($miles * 1.609344);
            } elseif ($unit == "N") {
                return ($miles * 0.8684);
            } else {
                return $miles;
            }
        }
    }

    private function calculateRideFares($distance_in_unit, $dropoff_time_in_seconds, $zone_type, $type_prices, $coupon_detail)
    {
        // $pickup_time_in_seconds = get_duration_value_from_distance_matrix($driver_to_pickup_response);
        $pickup_time_in_seconds = 0;
        $wait_time_in_seconds = 180; // can be change

        //$calculatable_distance = ($distance_in_unit - $type_prices->base_distance);
        //New Price Calculation Starts
        $calculatable_distance = ($distance_in_unit);
        //New Price Calculation Ends



        if ($calculatable_distance < 0) {

            $calculatable_distance = 0;
        }


        $price_per_distance = $type_prices->price_per_distance;

        // Validate if the current time in surge timings

        $timezone = $zone_type->zone->serviceLocation->timezone;

        $current_time = Carbon::now()->setTimezone($timezone);

        $current_time = $current_time->toTimeString();

        $zone_surge_price = ZoneSurgePrice::whereZoneId($zone_type->zone_id)->whereTime('start_time', '<=', $current_time)->whereTime('end_time', '>=', $current_time)->first();

        if ($zone_surge_price) {

            $surge_percent = $zone_surge_price->value;

            $surge_price_additional_cost = ($price_per_distance * ($surge_percent / 100));

            $price_per_distance += $surge_price_additional_cost;
        }

        //New Price Calculation Starts
        $zones_total_charges = 0;
        $zone_calculatable_distance = $calculatable_distance;

        $zones_a = $type_prices->zone_a;
        $zones_b = $type_prices->zone_b;
        $zones_c = $type_prices->zone_c;

        if ($zones_a && $zones_b && $zones_c) {

            $zones_a = explode(',', $zones_a);
            $zones_b = explode(',', $zones_b);
            $zones_c = explode(',', $zones_c);

            if ($calculatable_distance > $zones_c[0]) {
                $zone_c_distance = $zone_calculatable_distance - $zones_c[0];
                $zone_c_charges = ($zone_c_distance * $zones_c[2]);
                $zones_total_charges += $zone_c_charges;

                $zone_calculatable_distance -= $zone_c_distance;
            }
            if ($calculatable_distance > $zones_b[0]) {
                $zone_b_distance = $zone_calculatable_distance - $zones_b[0];
                $zone_b_charges = ($zone_b_distance * $zones_b[2]);
                $zones_total_charges += $zone_b_charges;
                // return $zones_total_charges;
                $zone_calculatable_distance -= $zone_b_distance;
            }
            if ($calculatable_distance > $zones_a[0]) {
                $zone_a_distance = $zone_calculatable_distance - $zones_a[0];
                $zone_a_charges = ($zone_a_distance * $zones_a[2]);
                $zones_total_charges += $zone_a_charges;
                $zone_calculatable_distance -= $zone_a_distance;
            }
            if ($calculatable_distance > $zones_a[0]) {
                $zones_total_charges += $type_prices->base_price;
            }
            if ($calculatable_distance < $zones_a[0]) {
                $zones_total_charges += $type_prices->base_price;
            }
        }
        //New Price Calculation Ends




        $distance_price = ($calculatable_distance * $price_per_distance);

        $time_price = ($dropoff_time_in_seconds / 60) * $type_prices->price_per_time;


        $base_price = $type_prices->base_price;


        // additon of base and distance price
        //        $base_and_distance_price = ($base_price + $distance_price);
        //New Price Calculation Starts
        $base_and_distance_price = $zones_total_charges;
        //New Price Calculation Ends

        // return $base_and_distance_price;


        $base_distance = $type_prices->base_distance;
        // if ($distance_in_unit < $base_distance) {
        //     $base_and_distance_price = $base_price;
        // }

        //$subtotal_price = $base_and_distance_price + $time_price;
        //$discount_amount = 0;
        //$coupon_applied_sub_total = $base_and_distance_price + $time_price;
        /* if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount < $subtotal_price) {
                $discount_amount = $subtotal_price * ($coupon_detail->discount_percent/100);
                if ($coupon_detail->maximum_discount_amount>0 && $discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }
                $coupon_applied_sub_total = $subtotal_price - $discount_amount;
            }
        }*/

        //New Price Calculation Starts
        $subtotal_price = $base_and_distance_price;
        $coupon_applied_sub_total = $base_and_distance_price;

        $discount_amount = 0;
        if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount < $subtotal_price) {
                $discount_amount = $subtotal_price * ($coupon_detail->discount_percent / 100);
                if ($coupon_detail->maximum_discount_amount > 0 && $discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }
                //  $coupon_applied_sub_total = $subtotal_price - $discount_amount;
            }
        }


        //New Price Calculation Ends





        // if trip distace is lessthan base distance, no need to calculate time price

        // Get Admin Commision
        // $service_fee = get_settings('admin_commission');
        $service_fee = $zone_type->admin_commision;

        if (($zone_type->admin_commission_type) == 1) {
            Log::info("inside");
            $service_fee  = ($coupon_applied_sub_total * ($service_fee / 100));
        }

        // Admin commision
        $without_discount_admin_commision = $service_fee;
        // dd($without_discount_admin_commision);

        // $tax_percent = get_settings('service_tax');
        $tax_percent = $zone_type->service_tax;

        $without_discount_admin_commision = (($subtotal_price + $discount_amount) * ($service_fee / 100));

        $with_out_discount_tax_amount = (($subtotal_price + $discount_amount) * ($tax_percent / 100));


        $total_price = $subtotal_price + $with_out_discount_tax_amount + $without_discount_admin_commision;





        $discount_admin_commision = ($coupon_applied_sub_total * ($service_fee / 100));
        $discount_tax_amount = $coupon_applied_sub_total * ($tax_percent / 100);
        $discounted_total_price = $coupon_applied_sub_total + $discount_tax_amount + $discount_admin_commision;

        // if (!request()->has('drop_lat') && !request()->has('drop_lng')) {
        //     $total_price = 0;
        // }
        $pickup_duration = $pickup_time_in_seconds / 60;
        $dropoff_duration = $dropoff_time_in_seconds / 60;
        $wait_duration = $wait_time_in_seconds / 60;
        $duration = $pickup_duration + $dropoff_duration + $wait_duration;

        return (object)[
            'distance' => round($distance_in_unit, 2),
            'base_distance' => $base_distance,
            'base_price' => $base_price,
            'price_per_distance' => $type_prices->price_per_distance,
            'price_per_time' => $type_prices->price_per_time,
            'distance_price' => $distance_price,
            'time_price' => $time_price,
            'subtotal_price' => $subtotal_price,
            'tax_percent' => $tax_percent,
            'tax_amount' => $with_out_discount_tax_amount,
            'discount_total_tax_amount' => $discount_tax_amount,
            'total_price' => $total_price,
            'discounted_total_price' => $discounted_total_price,
            'discount_amount' => $discount_amount,
            'pickup_duration' => round($pickup_duration),
            'dropoff_duration' => round($dropoff_duration),
            'wait_duration' => round($wait_duration),
            'duration' => round($duration),
        ];
    }


    private function db_query_previous_pickup_dropoff($pick_lat, $pick_lng, $drop_lat, $drop_lng)
    {
        return $this->db_query_nearest_distance_matrix(
            $pick_lat,
            $pick_lng,
            $drop_lat,
            $drop_lng,
            EtaConstants::PICKUP_RADIUS_IN_METERS,
            EtaConstants::DROPOFF_RADIUS_IN_METERS
        );
    }

    private function db_query_nearest_distance_matrix($pick_lat, $pick_lng, $drop_lat, $drop_lng, $radius1, $radius2)
    {
        $earth_radius = EtaConstants::EARTH_RADIUS_IN_METERS;
        $update_after = Carbon::now()->subMinute(EtaConstants::LOCATION_CACHE_TIME_IN_MINUTES)->toDateTimeString();

        // uses haversine formula for calculating distance
        $nearest_distance_matrix = DistanceMatrix::selectRaw("
      id,
      origin_addresses,
      ROUND($earth_radius *
        IFNULL(ACOS(
          COS( RADIANS(?) ) *
          COS( RADIANS(origin_lat) ) *
          COS( RADIANS(origin_lng) - RADIANS(?) ) +
          SIN( RADIANS(?) ) *
          SIN( RADIANS(origin_lat) )
        ), 0), 8) AS origin_distance,
      destination_addresses,
      ROUND($earth_radius *
        IFNULL(ACOS(
          COS( RADIANS(?) ) *
          COS( RADIANS(destination_lat) ) *
          COS( RADIANS(destination_lng) - RADIANS(?) ) +
          SIN( RADIANS(?) ) *
          SIN( RADIANS(destination_lat) )
        ), 0), 8) AS destination_distance,
      json_result", [
            $pick_lat,
            $pick_lng,
            $pick_lat,
            $drop_lat,
            $drop_lng,
            $drop_lat
        ])
            ->where("updated_at", ">=", $update_after)
            ->having("origin_distance", "<=", $radius1)
            ->having("destination_distance", "<=", $radius2)
            ->orderBy("origin_distance")
            ->orderBy("destination_distance")
            ->first();

        if (!$nearest_distance_matrix) {
            $nearest_distance_matrix =  $this->save_distance_matrix_from_google($pick_lat, $pick_lng, $drop_lat, $drop_lng, true);
        }
        return $nearest_distance_matrix;
    }
    public function save_distance_matrix_from_google($pick_lat, $pick_lng, $drop_lat, $drop_lng, $traffic)
    {
        $distance_matrix = get_distance_matrix($pick_lat, $pick_lng, $drop_lat, $drop_lng, $traffic);

        $carbonNow = Carbon::now()->toDateTimeString();

        if ($distance_matrix && $distance_matrix->status == 'OK') {
            $distance_matrix_params = [
                'origin_addresses' => $distance_matrix->origin_addresses[0],
                'origin_lat' => $pick_lat,
                'origin_lng' => $pick_lng,
                'destination_addresses' => $distance_matrix->destination_addresses[0],
                'destination_lat' => $drop_lat,
                'destination_lng' => $drop_lng,
                'distance' => get_distance_text_from_distance_matrix($distance_matrix) == null ? 0 : get_distance_text_from_distance_matrix($distance_matrix),
                'duration' => get_duration_text_from_distance_matrix($distance_matrix) == null ? 0 : get_duration_text_from_distance_matrix($distance_matrix),
                'json_result' => \GuzzleHttp\json_encode($distance_matrix)
            ];

            return $stored_distance_matrix_details = DistanceMatrix::create($distance_matrix_params);
        } else {
            $this->throwCustomException('Unable to calculate distance between coordinates');
        }
    }

    public function validate_promo_code($service_location)
    {
        $user = auth()->user();
        if (!request()->has('promo_code')) {
            return $coupon_detail = null;
        }
        $promo_code = request()->input('promo_code');
        // Validate if the promo is expired
        $current_date = Carbon::today()->toDateTimeString();

        $expired = Promo::where('code', $promo_code)->where('to', '>', $current_date)->where('service_location_id', $service_location)->where('active', true)->first();

        if (!$expired) {
            $this->throwCustomException('provided promo code expired or invalid');
        }
        // $exceed_usage = PromoUser::where('promo_code_id', $expired->id)->where('user_id', $user->id)->get()->count();
        // if ($exceed_usage >= $expired->uses_per_user) {
        //     $this->throwCustomException('you have exceeded your limit for this promo');
        // }
        // if ($expired->total_uses > $expired->total_uses+1) {
        //     $this->throwCustomException('provided promo code expired');
        // }
        return $expired;
    }
}
