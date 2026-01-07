<?php

namespace App\Http\Controllers\Api\V1\Payment\Dpo;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Base\Constants\Auth\Role;
use App\Http\Controllers\ApiController;
use App\Models\Payment\UserWalletHistory;
use App\Models\Payment\DriverWalletHistory;
use App\Transformers\Payment\WalletTransformer;
use App\Transformers\Payment\DriverWalletTransformer;
use App\Http\Requests\Payment\AddMoneyToWalletRequest;
use App\Transformers\Payment\UserWalletHistoryTransformer;
use App\Transformers\Payment\DriverWalletHistoryTransformer;
use App\Models\Payment\UserWallet;
use App\Models\Payment\DriverWallet;
use App\Base\Constants\Masters\WalletRemarks;
use App\Base\Constants\Setting\Settings;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Jobs\NotifyViaMqtt;
use App\Base\Constants\Masters\PushEnums;
use App\Models\Payment\OwnerWallet;
use App\Models\Payment\OwnerWalletHistory;
use App\Transformers\Payment\OwnerWalletTransformer;
use App\Models\Request\Request as RequestModel;
use  Kreait\Firebase\Database;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Jobs\Notifications\SendPushNotification;

/**
 * @group Paystack Payment Gateway
 *
 * Payment-Related Apis
 */
class DpoController extends ApiController
{

     public function __construct(Database $database)
    {
        $this->database = $database;
    }
    /**
     * Initialize Payment
     * 
     * 
     * 
     * */
    public function initialize(Request $request){


        $user_id = auth()->user()->id;;

        $amount = $request->amount;

        $company_reference = $request->request_for.'--'.$user_id;


        $endpoint = "https://secure.3gdirectpay.com/API/v6/";
        $xmlData = "<?xml version=\"1.0\" encoding=\"utf-8\"?><API3G><CompanyToken>8D3DA73D-9D7F-4E09-96D4-3D44E7A83EA3</CompanyToken><Request>createToken</Request><Transaction><PaymentAmount>{$amount}</PaymentAmount><PaymentCurrency>USD</PaymentCurrency><CompanyRef>{$company_reference}</CompanyRef><RedirectURL>https://tagxi-server.ondemandappz.com/dpo-success</RedirectURL><BackURL>http://tagxi-server.ondemandappz.com/api/v1/payment/dpo/web-hook</BackURL><CompanyRefUnique>0</CompanyRefUnique><PTL>5555</PTL></Transaction><Services><Service><ServiceType>3854</ServiceType><ServiceDescription>Flight from Nairobi to Diani</ServiceDescription><ServiceDate>2023/11/07 17:00</ServiceDate></Service></Services></API3G>";

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



    


    public function dpoWebHook(Request $request){

        Log::info("dpo-log");
        Log::info($request->all());


    }


    
}
