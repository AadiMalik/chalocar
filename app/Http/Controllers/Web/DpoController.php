<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Web\BaseController;
use Illuminate\Http\Request;
use App\Models\User;
use App\Base\Constants\Masters\WalletRemarks;
use App\Models\Payment\UserWallet;
use App\Models\Payment\DriverWallet;
use App\Models\Payment\OwnerWallet;
use App\Models\Payment\OwnerWalletHistory;
use App\Models\Payment\UserWalletHistory;
use App\Models\Payment\DriverWalletHistory;
use App\Base\Constants\Masters\PushEnums;
use App\Jobs\Notifications\SendPushNotification;

class DpoController extends BaseController
{
    
    public function success(Request $request){

        // dd($request->all());

        $endpoint = "https://secure.3gdirectpay.com/API/v6/";

        $transaction_token = $request->TransactionToken;

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


$result = simplexml_load_string($result);

$exploded_reference = explode('--', $request->CompanyRef);

$user_id = $exploded_reference[1];

$response = (string)$result->Result;

$transaction_id = $request->TransID;

$requested_amount = $result->TransactionAmount;

if($response!='000'){

    $this->throwCustomException('Payment Failed');
}

        $request_for = $exploded_reference[0];

        $user = User::find($user_id);


        if($request_for =='FR'){

            $request_id = $user->requestDetail()->where('is_completed', true)->where('is_cancelled', false)->where('user_rated', false)->where('driver_id', '!=', null)->first();

            $this->makePaymentForRide($request_id,$transaction_id);

            goto end;
        }

        if ($user->hasRole('user')) {
        $wallet_model = new UserWallet();
        $wallet_add_history_model = new UserWalletHistory();
        } elseif($user->hasRole('driver')) {
                    $wallet_model = new DriverWallet();
                    $wallet_add_history_model = new DriverWalletHistory();
                    $user_id = $user->driver->id;
        }else {
                    $wallet_model = new OwnerWallet();
                    $wallet_add_history_model = new OwnerWalletHistory();
                    $user_id = $user->owner->id;
        }

        $user_wallet = $wallet_model::firstOrCreate([
            'user_id'=>$user_id]);
        $user_wallet->amount_added += $requested_amount;
        $user_wallet->amount_balance += $requested_amount;
        $user_wallet->save();
        $user_wallet->fresh();

        $wallet_add_history_model::create([
            'user_id'=>$user_id,
            'amount'=>$requested_amount,
            'transaction_id'=>$request->payment_id,
            'remarks'=>WalletRemarks::MONEY_DEPOSITED_TO_E_WALLET,
            'is_credit'=>true]);


                $pus_request_detail = json_encode($request->all());

                $socket_data = new \stdClass();
                $socket_data->success = true;
                $socket_data->success_message  = PushEnums::AMOUNT_CREDITED;
                $socket_data->result = $request->all();

                $title = trans('push_notifications.amount_credited_to_your_wallet_title',[],$user->lang);
                $body = trans('push_notifications.amount_credited_to_your_wallet_body',[],$user->lang);

                // dispatch(new NotifyViaMqtt('add_money_to_wallet_status'.$user_id, json_encode($socket_data), $user_id));

                dispatch(new SendPushNotification($user,$title,$body));

        end:
        return view('success',['success']);


    }


     /**
     * Make Payment At end of the ride
     * 
     * */
    public function makePaymentForRide($request_id,$transaction_id){

        $request_detail = RequestModel::find($request_id); 

        $driver = $request_detail->driverDetail;    

        //  Update payement status
        $request_detail->is_paid = 1;

        $request_detail->save();

        $driver_commision = $request_detail->requestBill->driver_commision;

        $user_wallet = DriverWallet::firstOrCreate([
            'user_id'=>$driver->id]);

        $user_wallet->amount_added += $driver_commision;
        $user_wallet->amount_balance += $driver_commision;
        $user_wallet->save();
        $user_wallet->fresh();

        DriverWalletHistory::create([
            'user_id'=>$driver->id,
            'amount'=>$driver_commision,
            'transaction_id'=>$transaction_id,
            'remarks'=>WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
            'is_credit'=>true]);

        $this->database->getReference('requests/'.$request_detail->id)->update(['is_paid'=>1,'updated_at'=> Database::SERVER_TIMESTAMP]);

        $title = trans('push_notifications.payment_completed_by_user_title',[],$driver->user->lang);
        $body = trans('push_notifications.payment_completed_by_user_body',[],$driver->user->lang);

        dispatch(new SendPushNotification($driver->user,$title,$body));

        return view('success',['success']);

    }

}
