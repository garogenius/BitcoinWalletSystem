<?php

namespace App\Http\Controllers;

use App\Lib\GoogleAuthenticator;
use App\Models\AdminNotification;
use App\Models\GeneralSetting;
use App\Models\Transaction;
use App\Models\WithdrawMethod;
use App\Models\UserWallet;
use App\Models\Send;
use App\Models\SupportTicket;
use App\Rules\FileTypeValidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Gateway\Blockio\BlockIo;


class UserController extends Controller
{
    public function __construct()
    {
        $this->activeTemplate = activeTemplate();
    }

    public function home()
    {
        $pageTitle = 'Dashboard';

        $user = Auth::user();

        $latestTrxs = Transaction::where('user_id', $user->id)->with('wallet')->latest()->limit(10)->get();

        $totalTrx = Transaction::where('user_id', $user->id)->count();

        $btcBalance = UserWallet::where('user_id', $user->id)->sum('balance');

        $totalWallet = UserWallet::where('user_id', $user->id)->count();

        $totalSend = Send::where('user_id', $user->id)->where('status', 1)->sum('amount');

        $totalReceive = Transaction::where('user_id', $user->id)->where('trx_type', '+')->sum('amount');

        return view($this->activeTemplate . 'user.dashboard', compact('pageTitle','latestTrxs','user','totalTrx','btcBalance','totalWallet','totalSend', 'totalReceive'));
    }

    public function profile()
    {
        $pageTitle = "Profile Setting";
        $user = Auth::user();
        return view($this->activeTemplate. 'user.profile_setting', compact('pageTitle','user'));
    }

    public function submitProfile(Request $request)
    {
        $request->validate([
            'firstname' => 'required|string|max:50',
            'lastname' => 'required|string|max:50',
            'address' => 'sometimes|required|max:80',
            'state' => 'sometimes|required|max:80',
            'zip' => 'sometimes|required|max:40',
            'city' => 'sometimes|required|max:50',
            'image' => ['image',new FileTypeValidate(['jpg','jpeg','png'])]
        ],[
            'firstname.required'=>'First name field is required',
            'lastname.required'=>'Last name field is required'
        ]);

        $user = Auth::user();

        $in['firstname'] = $request->firstname;
        $in['lastname'] = $request->lastname;

        $in['address'] = [
            'address' => $request->address,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => @$user->address->country,
            'city' => $request->city,
        ];


        if ($request->hasFile('image')) {
            $location = imagePath()['profile']['user']['path'];
            $size = imagePath()['profile']['user']['size'];
            $filename = uploadImage($request->image, $location, $size, $user->image);
            $in['image'] = $filename;
        }
        $user->fill($in)->save();
        $notify[] = ['success', 'Profile updated successfully.'];
        return back()->withNotify($notify);
    }

    public function changePassword()
    {
        $pageTitle = 'Change password';
        return view($this->activeTemplate . 'user.password', compact('pageTitle'));
    }

    public function submitPassword(Request $request)
    {

        $password_validation = Password::min(6);
        $general = GeneralSetting::first();
        if ($general->secure_password) {
            $password_validation = $password_validation->mixedCase()->numbers()->symbols()->uncompromised();
        }

        $this->validate($request, [
            'current_password' => 'required',
            'password' => ['required','confirmed',$password_validation]
        ]);


        try {
            $user = auth()->user();
            if (Hash::check($request->current_password, $user->password)) {
                $password = Hash::make($request->password);
                $user->password = $password;
                $user->save();
                $notify[] = ['success', 'Password changes successfully.'];
                return back()->withNotify($notify);
            } else {
                $notify[] = ['error', 'The password doesn\'t match!'];
                return back()->withNotify($notify);
            }
        } catch (\PDOException $e) {
            $notify[] = ['error', $e->getMessage()];
            return back()->withNotify($notify);
        }
    }

    public function show2faForm()
    {
        $general = GeneralSetting::first();
        $ga = new GoogleAuthenticator();
        $user = auth()->user();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeGoogleUrl($user->username . '@' . $general->sitename, $secret);
        $pageTitle = 'Two Factor';
        return view($this->activeTemplate.'user.twofactor', compact('pageTitle', 'secret', 'qrCodeUrl'));
    }

    public function create2fa(Request $request)
    {
        $user = auth()->user();
        $this->validate($request, [
            'key' => 'required',
            'code' => 'required',
        ]);
        $response = verifyG2fa($user,$request->code,$request->key);
        if ($response) {
            $user->tsc = $request->key;
            $user->ts = 1;
            $user->save();
            $userAgent = getIpInfo();
            $osBrowser = osBrowser();
            notify($user, '2FA_ENABLE', [
                'operating_system' => @$osBrowser['os_platform'],
                'browser' => @$osBrowser['browser'],
                'ip' => @$userAgent['ip'],
                'time' => @$userAgent['time']
            ]);
            $notify[] = ['success', 'Google authenticator enabled successfully'];
            return back()->withNotify($notify);
        } else {
            $notify[] = ['error', 'Wrong verification code'];
            return back()->withNotify($notify);
        }
    }


    public function disable2fa(Request $request)
    {
        $this->validate($request, [
            'code' => 'required',
        ]);

        $user = auth()->user();
        $response = verifyG2fa($user,$request->code);
        if ($response) {
            $user->tsc = null;
            $user->ts = 0;
            $user->save();
            $userAgent = getIpInfo();
            $osBrowser = osBrowser();
            notify($user, '2FA_DISABLE', [
                'operating_system' => @$osBrowser['os_platform'],
                'browser' => @$osBrowser['browser'],
                'ip' => @$userAgent['ip'],
                'time' => @$userAgent['time']
            ]);
            $notify[] = ['success', 'Two factor authenticator disable successfully'];
        } else {
            $notify[] = ['error', 'Wrong verification code'];
        }
        return back()->withNotify($notify);
    }

    public function trxHistory(Request $request){

        $shortBy = $request->shortBy;
        $walletId = $request->wallet;

        $trxType = $shortBy == 'Debit' ? '-' : '+';

        $logs = Transaction::where('user_id', Auth::user()->id)
                            ->when(isset($shortBy), function($query) use ($trxType) {
                                $query->where('trx_type', $trxType);
                            })
                            ->when(isset($walletId), function($query2) use ($walletId) {
                                $query2->where('wallet_id', $walletId);
                            })
                           ->latest()
                           ->with('wallet')
                           ->paginate(getPaginate());

        $wallets = UserWallet::where('user_id', Auth::user()->id)->latest()->get();

        $pageTitle = 'Transaction History';
        return view($this->activeTemplate.'user.trx_history', compact('pageTitle', 'logs', 'shortBy', 'wallets', 'walletId'));
    }

    public function addWallet(Request $request){

        $request->validate([
            'name' => [
                'sometimes',
                Rule::unique('user_wallets')->where(function ($query) use($request) {
                    return $query->where('user_id', Auth::user()->id)
                    ->where('name', $request->name);
                }),
            ]
        ]);

        $user = Auth::user();
        $general = GeneralSetting::first();
        $wallet = UserWallet::where('user_id', $user->id)->count();

        if($wallet >= $general->wallet_limit){
            $notify[] = ['error', 'Sorry, you can not add more than '.$general->wallet_limit.' wallet'];
            return back()->withNotify($notify);
        }

        try{
            $apiKey = $general->api;
            $version = $general->api_version;
            $pin =  $general->pin;
            $block_io = new BlockIo($apiKey, $pin, $version);
            $response = $block_io->get_new_address();
        }catch(\Exception $ex){
            $notify[] = ['error', $ex->getMessage()];
            return back()->withNotify($notify);
        }

        $wallet = new UserWallet();
        $wallet->user_id = $user->id;
        $wallet->name = $request->name;
        $wallet->coin_code = 'BTC';
        $wallet->wallet_address = $response->data->address;
        $wallet->save();

        $notify[] = ['success', 'New wallet added successfully'];
        return back()->withNotify($notify);
    }

    public function wallet(){
        $pageTitle = 'Wallet';
        $user = Auth::user();
        $wallets  = UserWallet::where('user_id', $user->id)->latest()->paginate(getPaginate());
        return view($this->activeTemplate.'user.wallet', compact('pageTitle', 'wallets'));
    }

    public function sendPage(){
        $pageTitle = 'Send Balance';
        $user = Auth::user();
        $wallets  = UserWallet::where('user_id', $user->id)->latest()->paginate(getPaginate());
        return view($this->activeTemplate.'user.send', compact('pageTitle', 'wallets'));
    }

    public function send(Request $request){

        $request->validate([
            'send_wallet'=> 'required|max:255|not-in:'.$request->wallet_address,
            'btc_amount'=> 'required|numeric|gt:0',
            'wallet_address' => [     // From Wallet Address
                Rule::exists('user_wallets')->where(function ($query) use ($request) {
                    return $query->where('wallet_address', $request->wallet_address)
                    ->where('user_id', Auth::user()->id);
                }),
            ]
        ]);

        $general = GeneralSetting::first();

        $charge = $general->fixed_charge + ($request->btc_amount * $general->percent_charge / 100);
        $requiredBalance = $request->btc_amount + $charge;

        $user = Auth::user();
        $findWallet = UserWallet::where('user_id', $user->id)->where('wallet_address', $request->wallet_address)->first();

        if($findWallet->balance < $requiredBalance){
            $notify[] = ['error', 'Sorry, Insufficient Balance'];
            return back()->withNotify($notify);
        }

        if ($user->ts) {
            $response = verifyG2fa($user, $request->authenticator_code);
            if (!$response) {
                $notify[] = ['error', 'Wrong verification code'];
                return back()->withNotify($notify);
            }
        }

        $send = new Send();
        $send->user_id = $user->id;
        $send->wallet_id = $findWallet->id; // Send Wallet Address
        $send->receive_wallet = $request->send_wallet;
        $send->amount = $request->btc_amount;
        $send->charge = $charge;
        $send->status = 0;
        $send->trx = getTrx();
        $send->save();

        $findWallet->balance -= $requiredBalance;
        $findWallet->save();

        $transaction = new Transaction();
        $transaction->user_id = $user->id;
        $transaction->wallet_id = $findWallet->id;
        $transaction->amount = $request->btc_amount;
        $transaction->post_balance = $findWallet->balance;
        $transaction->charge = $charge;
        $transaction->trx_type = '-';
        $transaction->details = 'Send '.$request->btc_amount.' '.$general->cur_text.' To '.$request->send_wallet;
        $transaction->trx = $send->trx;
        $transaction->save();

        notify($user, 'BAL_SEND', [

            'trx' => $transaction->trx,
            'amount' => showAmount($request->btc_amount, 8),
            'currency' => $general->cur_text,
            'post_balance' => showAmount($findWallet->balance, 8),
            'wallet' => $findWallet->wallet_address,
            'wallet_name' => $findWallet->name ?? 'N/A'
        ]);

        $adminNotification = new AdminNotification();
        $adminNotification->user_id = $user->id;
        $adminNotification->title = $user->username.' has sent '.$request->btc_amount.' '.$general->cur_text.' To '.$request->send_wallet;
        $adminNotification->click_url = urlPath('admin.users.send.history',$user->id);
        $adminNotification->save();

        $notify[] = ['success', $request->btc_amount.' '.$general->cur_text.' will send within few minutes'];
        return redirect()->route('user.send.history')->withNotify($notify);
    }

    public function sendHistory(){
        $pageTitle = 'Send History';
        $logs  = Send::where('user_id', Auth::user()->id)->latest()->with('wallet')->paginate(getPaginate());
        return view($this->activeTemplate.'user.send_history', compact('pageTitle', 'logs'));
    }

    public function receiveHistory(Request $request){

        $walletId = $request->wallet;

        $logs = Transaction::where('user_id', Auth::user()->id)
                            ->when(isset($walletId), function($query2) use ($walletId) {
                                $query2->where('wallet_id', $walletId);
                            })
                            ->where('trx_type', '+')
                           ->latest()
                           ->with('wallet')
                           ->paginate(getPaginate());

        $wallets = UserWallet::where('user_id', Auth::user()->id)->latest()->get();

        $pageTitle = 'Transaction History';
        return view($this->activeTemplate.'user.receive_history', compact('pageTitle', 'logs', 'wallets', 'walletId'));
    }



}
