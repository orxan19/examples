<?php

namespace App\Http\Controllers;

use App\Activity;
use App\BuyerCampaign;
use App\BuyerUniqueId;
use App\BuyerWallet;
use App\BuyerWalletLog;
use App\Campaign;
use App\CampaignDetails;
use App\CampaignForBuyer;
use App\CampaignViewCount;
use App\CoinLog;
use App\CoinSetting;
use App\Favorite;
use App\MainCategory;
use App\Rebate;
use App\Schedule;
use App\TrafficEmailCount;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;

class BuyerController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('ifBuyer');
    }

    public function product_buy(Request $request)
    {
        $user = Auth::user();

        $schedule = Schedule::where('id', $request->id)->with('available_rebates', 'campaign')->first();

        $activity = Activity::where('schedule_id', $schedule->id)->where('buyer_id', $user->id)
            ->orderBy('id', 'desc')->first();

        $campaign = Campaign::findOrFail($schedule->campaign_id);

        if ($campaign->online_schedules[$campaign->online_schedules->count() - 1]->id == $schedule->id) {
            if ($campaign->condition_status == 3) {

                if ($activity->status == 1) {

                    if ($schedule->available_rebates->count() == 0) {
                        $campaign = Campaign::where('id', $schedule->campaign->id)->first();
                        $campaign->available_status = 0;
                        $campaign->save();
                    }

                    $activity->delete();

                    $rebate = Rebate::where('schedule_id', $schedule->id)->where('buyer_id', $user->id)->first();
                    $rebate->order_id = $request->order_id;
                    $rebate->buy_success = 1;
                    $rebate->buy_at = Carbon::now();
                    $rebate->needs_approval_at = Carbon::now()->addDays(30);
                    $rebate->approved_at = Carbon::now()->addDays(35);
                    $rebate->save();

                    $buyer_wallet = BuyerWallet::where('buyer_id', $user->id)->first();
                    $buyer_wallet->upcoming = $buyer_wallet->upcoming + ($schedule->campaign->old_price - $schedule->campaign->new_price);
                    $buyer_wallet->save();

                    $wallet_log = new BuyerWalletLog;
                    $wallet_log->buyer_id = $user->id;
                    $wallet_log->rebate_id = $rebate->id;
                    $wallet_log->wallet_type = 1;
                    $wallet_log->amount = ($schedule->campaign->old_price - $schedule->campaign->new_price);
                    $wallet_log->operation = 1;
                    $wallet_log->payout_at = Carbon::now();
                    $wallet_log->status = 1;
                    $wallet_log->order_id = $request->order_id;
                    $wallet_log->read_status = 0;
                    $wallet_log->save();

                    $coin_log = new CoinLog;
                    $coin_log->buyer_id = $user->id;
                    $coin_log->amount = $campaign->coins;
                    $coin_log->status = 3;
                    $coin_log->save();

                    $user->coins = $user->coins + $campaign->coins;
                    $user->save();

                    if ($user->ref_id) {
                        $ref_user = User::where('id', $user->ref_id)->firstOrFail();

                        $coin_setting = CoinSetting::firstOrFail();

                        $coin_log = new CoinLog;
                        $coin_log->buyer_id = $ref_user->id;
                        $coin_log->amount = $coin_setting->reference_amount;
                        $coin_log->status = 4;
                        $coin_log->save();

                        $ref_user->coins = $ref_user->coins + $coin_setting->reference_amount;
                        $ref_user->save();
                    }


                    try {
                        $data = array(
                            'campaign' => $campaign,
                            'rebate' => $rebate,
                        );

                        Mail::send('dashboard.buyer.emails.get_free_bought', $data, function ($message) use ($user) {
                            $message->to($user->email, 'Testmarket.io')
                                ->subject("Re: Your apply is successfully done!");
                            $message->from("news@testmarket.io", "TestMarket Support Team");
                        });
                    } catch (\Exception $e) {

                    }

                    Session::put('productBuySuccess', 'success');
                    return redirect('/buyer/purchase/pre');
                }

            }
        } else {
            abort(404);
        }


    }

    public function product_buy_unlimited(Request $request)
    {
        $user = Auth::user();

        $campaign = Campaign::findOrFail($request->id);


        $activity = Activity::where('campaign_id', $campaign->id)->where('buyer_id', $user->id)
            ->orderBy('id', 'desc')->first();


        if ($campaign->condition_status == 3) {

            if ($activity->status == 1) {

                if ($campaign->available_rebates->count() == 0) {
                    $campaign->available_status = 0;
                    $campaign->save();
                }

                $activity->delete();

                $rebate = Rebate::where('campaign_id', $campaign->id)->where('buyer_id', $user->id)->first();
                $rebate->order_id = $request->order_id;
                $rebate->buy_success = 1;
                $rebate->buy_at = Carbon::now();
                $rebate->needs_approval_at = Carbon::now()->addDays(30);
                $rebate->approved_at = Carbon::now()->addDays(35);
                $rebate->save();


                if ($campaign->available_rebates->count() == 0) {

                    $flag_all = 0;
                    foreach ($campaign->rebates as $available_rebate_unlimited) {
                        if ($available_rebate_unlimited->buy_success == 0) {
                            $flag_all = 1;
                            break;
                        }
                    }

                    if ($flag_all) {

                    } else {
                        $campaign->condition_status = 4;
                        $campaign->available_status = 0;
                    }
                    $campaign->save();

                }


                $buyer_wallet = BuyerWallet::where('buyer_id', $user->id)->first();
                $buyer_wallet->upcoming = $buyer_wallet->upcoming + ($campaign->old_price - $campaign->new_price);
                $buyer_wallet->save();

                $wallet_log = new BuyerWalletLog;
                $wallet_log->buyer_id = $user->id;
                $wallet_log->rebate_id = $rebate->id;
                $wallet_log->wallet_type = 1;
                $wallet_log->amount = ($campaign->old_price - $campaign->new_price);
                $wallet_log->operation = 1;
                $wallet_log->payout_at = Carbon::now();
                $wallet_log->status = 1;
                $wallet_log->order_id = $request->order_id;
                $wallet_log->read_status = 0;
                $wallet_log->save();

                $coin_log = new CoinLog;
                $coin_log->buyer_id = $user->id;
                $coin_log->amount = $campaign->coins;
                $coin_log->status = 3;
                $coin_log->save();

                $user->coins = $user->coins + $campaign->coins;
                $user->save();

                if ($user->ref_id) {
                    $ref_user = User::where('id', $user->ref_id)->firstOrFail();

                    $coin_setting = CoinSetting::firstOrFail();

                    $coin_log = new CoinLog;
                    $coin_log->buyer_id = $ref_user->id;
                    $coin_log->amount = $coin_setting->reference_amount;
                    $coin_log->status = 4;
                    $coin_log->save();

                    $ref_user->coins = $ref_user->coins + $coin_setting->reference_amount;
                    $ref_user->save();
                }

                Session::put('productBuySuccess', 'success');
                return redirect('/buyer/purchase/pre');
            }

        }
        
    }

    public function profile()
    {
        $user = Auth::user();

        $country = config('app.countries')[182];
        $states = $country['states'];
        return view('dashboard.buyer.profile', compact('user', 'country', 'states'));
    }

    public function profile_update(Request $request)
    {
        $user = Auth::user();

        $validatedData = $request->validate([
            'full_name' => 'required|max:255',
            'address_1' => 'required|max:255',
            'address_2' => 'max:255',
            'zip_code' => 'required|max:255',
            'state' => 'required|max:255',
            'city' => 'required|max:255',
        ]);

        $user->full_name = $validatedData['full_name'];
        $user->buyer_address_line_1 = $validatedData['address_1'];
        $user->buyer_address_line_2 = $validatedData['address_2'];
        $user->buyer_zip_code = $validatedData['zip_code'];
        $user->country = 'United States';
        $user->state = $validatedData['state'];
        $user->city = $validatedData['city'];
        $user->save();

        Session::put('addressUpdated', 'success');
        return redirect('/buyer/profile');
    }


    public function profile_avatar_update(Request $request)
    {
        $this->validate($request, [
            'avatar' => 'image|mimes:jpeg,png,jpg,gif,svg',
        ]);


        if ($this->storeImage($request)) {
            Session::put('profileAvatarUpdated', 1);
            return redirect('/buyer/profile');
        } else {
            return back();
        }
    }

    public function storeImage($request)
    {
        $file = $request->file('avatar');

        $filenameWithExt = $file->getClientOriginalName();

        $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);

        $filename = preg_replace("/[^A-Za-z0-9 ]/", '', $filename);
        $filename = preg_replace("/\s+/", '-', $filename);

        $extension = $file->getClientOriginalExtension();

        $fileNameToStore = $filename . '_' . time() . '.' . $extension;

        $save = $this->resizeImage($file, $fileNameToStore);

        return $save;
    }

    /**
     * Resizes a image using the InterventionImage package.
     *
     * @param object $file
     * @param string $fileNameToStore
     * @return bool
     * @author Niklas Fandrich
     */
    public function resizeImage($file, $fileNameToStore)
    {
        $resize = Image::make($file)->resize(600, null, function ($constraint) {
            $constraint->aspectRatio();
        })->encode('jpg');

        $hash = md5($resize->__toString());

        $image = $hash . "jpg";

        $save = Storage::put("public/buyer/avatars/{$fileNameToStore}", $resize->__toString());

        if ($save) {
            $user = Auth::user();
            $user->buyer_avatar = "public/buyer/avatars/{$fileNameToStore}";
            $user->save();

            return true;
        }

        return false;


    }


    public function profile_password()
    {
        $user = Auth::user();

        return view('dashboard.buyer.password', compact('user'));
    }

    public function profile_password_update(Request $request)
    {
        $user = Auth::user();

        if ($user->password_status == 0) {
            $validatedData = $request->validate([
                'password' => ['required', 'string', 'min:6', 'confirmed'],
            ]);

            $user->password = Hash::make($validatedData['password']);
            $user->password_status = 1;
            $user->save();
            Session::put('profilePasswordUpdated', 1);

        } else {
            $validatedData = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:6', 'confirmed'],
            ]);

            if (Hash::check($validatedData['current_password'], $user->password)) {
                $user->password = Hash::make($validatedData['password']);
                $user->save();
                Session::put('profilePasswordUpdated', 1);
            } else {
                Session::put('profilePasswordNotMatch', 1);
                return redirect('/buyer/profile/password');
            }
        }


        return view('dashboard.buyer.password', compact('user'));
    }

}
