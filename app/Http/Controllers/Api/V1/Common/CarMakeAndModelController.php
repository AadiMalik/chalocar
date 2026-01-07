<?php

namespace App\Http\Controllers\Api\V1\Common;

use App\Models\Master\CarMake;
use App\Models\Master\CarModel;
use App\Http\Controllers\Api\V1\BaseController;
use Carbon\Carbon;
use Sk\Geohash\Geohash;
use Illuminate\Http\Request;
use App\Models\DummyTableForTesting;
use App\Models\Admin\Driver;
use App\Models\Request\RequestBill;
use App\Models\Request\Request as RequestModel;
use DB;
use  Kreait\Firebase\Database;
//use  Kreait\Firebase\Contract\Database;

/**
 * @group Vehicle Management
 *
 * APIs for vehilce management apis. i.e types,car makes,models apis
 */
class CarMakeAndModelController extends BaseController
{
    protected $car_make;
    protected $car_model;

    public function __construct(CarMake $car_make, CarModel $car_model,Database $database)
    {
        $this->car_make = $car_make;
        $this->car_model = $car_model;
        $this->database = $database;

    }

    /**
    * Get All Car makes
    *
    */
    public function getCarMakes()
    { 
        if(request()->has('vehicle_type')){

        return $this->respondSuccess($this->car_make->active()->orderBy('name')->where('vehicle_make_for',request()->vehicle_type)->get());

        }else{
            return $this->respondSuccess($this->car_make->active()->orderBy('name')->get());
        }
    }

    public function getAppModule()
    {

        $enable_owner_login =  get_settings('shoW_owner_module_feature_on_mobile_app');

        $enable_email_otp =  get_settings('shoW_email_otp_feature_on_mobile_app');


        return response()->json(['success'=>true,"message"=>'success','enable_owner_login'=>$enable_owner_login,'enable_email_otp'=>$enable_email_otp]);

    }


   public function matrix()
   {

    $a = array(
    array(50, 15, 85, 50),
    array(66, 50, 50, 26),
    array(14, 50, 50, 28),
    array(40, 21, 47, 50),

   );

    $count = count($a);


        $firstD = 0;
        $secondD = 0;

        $i = 0;
        for($j = 0; $j < $count; $j++)
        {
    // dd($a[$i++][0]);

        $firstD += $a[$i++][$j];

        $secondD += $a[$count - $i][$j];
        }

        $value =abs($firstD - $secondD);

    dd($value);
   }

    /**
    * Get Car models by make id
    * @urlParam make_id  required integer, make_id provided by user
    */
    public function getCarModels($make_id)
    {
        return $this->respondSuccess($this->car_model->where('make_id', $make_id)->active()->orderBy('name')->get());
    }


    

    /**
     * Test Api
     * 
     * */
    public function testApi(Request $request){


        // Verify token

         $endpoint = "https://secure.3gdirectpay.com/API/v6/";

        $transaction_token = '3D8E7279-28B4-48E4-9F09-88DCEE886F8A';

        $xmlData = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<API3G>
  <CompanyToken>8D3DA73D-9D7F-4E09-96D4-3D44E7A83EA3</CompanyToken>
  <Request>verifyToken</Request>
  <TransactionToken>{$transaction_token}</TransactionToken>
</API3G>";

$ch = curl_init();

if (!$ch) {
    die("Couldn't initialize a cURL handle");
}
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);

$result = curl_exec($ch);

curl_close($ch);


dd($result);




        $request_for = 'FW';

        $user_id = 5;

        $company_reference = $request_for.'--'.$user_id;


        $endpoint = "https://secure.3gdirectpay.com/API/v6/";
        $xmlData = "<?xml version=\"1.0\" encoding=\"utf-8\"?><API3G><CompanyToken>8D3DA73D-9D7F-4E09-96D4-3D44E7A83EA3</CompanyToken><Request>createToken</Request><Transaction><PaymentAmount>10.00</PaymentAmount><PaymentCurrency>USD</PaymentCurrency><CompanyRef>{$company_reference}</CompanyRef><RedirectURL>https://tagxi-server.ondemandappz.com/dpo-success</RedirectURL><BackURL>http://tagxi-server.ondemandappz.com/api/v1/payment/dpo/web-hook</BackURL><CompanyRefUnique>0</CompanyRefUnique><PTL>5555</PTL></Transaction><Services><Service><ServiceType>3854</ServiceType><ServiceDescription>Flight from Nairobi to Diani</ServiceDescription><ServiceDate>2023/11/07 17:00</ServiceDate></Service></Services></API3G>";

$ch = curl_init();

if (!$ch) {
    die("Couldn't initialize a cURL handle");
}
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);

$result = curl_exec($ch);

curl_close($ch);


$result = simplexml_load_string($result);

$response = (string)$result->Result;


if($response!='000'){

    $this->throwCustomException('Configurations missing or somthing went wrong with the gateway response');
}
$transToken = (string)$result->TransToken;


return response()->json(['success'=>true,'message'=>'success','transaction_token'=>$transToken]);
       
    }


    /**
     * Test Distance Matrix Api
     * @bodyParam pick_lat double required pikup lat of the user
     * @bodyParam pick_lng double required pikup lng of the user
     * @bodyParam drop_lat double required drop lat of the user
     * @bodyParam drop_lng double required drop lng of the user
     * 
     * */
    public function testDistanceMatrixApi(Request $request){

        $request->validate([
        'pick_lat' => 'required',
        'pick_lng' => 'required',
        'drop_lat' => 'required',
        'drop_lng' => 'required',
        'map_key' => 'sometimes|required'
        ]);

        // Test the Distance Matrix by provided lat & long

        if($request->has('map_key') && $request->map_key){

            $distance_matrix_result = get_distance_matrix_of_clients($request->pick_lat, $request->pick_lng, $request->drop_lat, $request->drop_lng,$request->map_key);    
        }else{

            $distance_matrix_result = get_distance_matrix($request->pick_lat, $request->pick_lng, $request->drop_lat, $request->drop_lng,true,$request->map_key);
        }
        

        if($distance_matrix_result->status=='OK'){
            return $this->respondSuccess($distance_matrix_result);

        }else{

            return response()->json(['success'=>false,'message'=>'there is an error with your map key','error'=>$distance_matrix_result]);
        }

    }




    /**
    * Get Drivers from firebase
    */
    public function getFirebaseDrivers($request)
    {
        $pick_lat = $request->current_lat;
        $pick_lng = $request->current_lng;

        // NEW flow
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

        $fire_drivers = $this->database->getReference('drivers')->orderByChild('g')->startAt($lower_hash)->endAt($higher_hash)->getValue();

                $firebase_drivers = [];

        $i=-1;

        foreach ($fire_drivers as $key => $fire_driver) {
            $i +=1;

                $distance = distance_between_two_coordinates($pick_lat,$pick_lng,$fire_driver['l'][0],$fire_driver['l'][1],'K');

                if($distance <= $driver_search_radius){

                    $firebase_drivers[$fire_driver['id']]['distance']= $distance;

                }   

        }


           if (!empty($firebase_drivers)) {

            $nearest_driver_ids = [];

                foreach ($firebase_drivers as $key => $firebase_driver) {

                    $nearest_driver_ids[]=$key;
                }

            }else{

                $nearest_driver_ids=[];
            }



            return $nearest_driver_ids;

    
    }
}
