<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Models\User;
use App\Models\Admin\Driver;
use App\Base\Constants\Auth\Role;
use App\Http\Controllers\ApiController;
use App\Transformers\User\UserTransformer;
use App\Transformers\Driver\DriverProfileTransformer;
use App\Transformers\Owner\OwnerProfileTransformer;
use App\Transformers\User\DispatcherTransformer;

class AccountController extends ApiController
{
    /**
     * Get the current logged in user.
     * @group User-Management
     * @return \Illuminate\Http\JsonResponse
     * @responseFile responses/auth/authenticated_driver.json
     * @responseFile responses/auth/authenticated_user.json
     */
    public function me()
    {

        $user = auth()->user();

        if ($user->hasRole(Role::DRIVER)) {

            $driver_details = $user->driver;
            $driver_documents = $driver_details->driverDocument??[]; // Assuming this is a collection

            $hasExpiredDocument = false;

            // Loop through each document to check expiry
            foreach ($driver_documents as $doc) {
                if ($doc->expiry_date && now()->gt($doc->expiry_date)) {
                    $hasExpiredDocument = true;
                    break; // No need to check further, one expired document is enough
                }
            }
            // If any document expired and driver was approved, set is_approve to 0
            if ($hasExpiredDocument && $driver_details->approve == 1) {
                $driver_details->approve = 0;
                $driver_details->save();
            }

            $user = fractal($driver_details, new DriverProfileTransformer)
                ->parseIncludes([
                    'onTripRequest.userDetail',
                    'onTripRequest.requestBill',
                    'metaRequest.userDetail',
                    'driverVehicleType'
                ]);
        } else if (auth()->user()->hasRole(Role::USER)) {

            $user = fractal($user, new UserTransformer)->parseIncludes(['onTripRequest.driverDetail', 'onTripRequest.requestBill', 'metaRequest.driverDetail', 'favouriteLocations', 'laterMetaRequest.driverDetail']);
        } else {

            $owner_details = $user->owner;

            $user = fractal($owner_details, new OwnerProfileTransformer);
        }

        if (auth()->user()->hasRole(Role::DISPATCHER)) {

            $user = User::where('id', auth()->user()->id)->first();

            // dd($user->Admin->serviceLocationDetail);

            $user = fractal($user, new DispatcherTransformer)->parseIncludes(['serviceLocation', 'zone', 'airport']);
        }

        return $this->respondOk($user);
    }
}
