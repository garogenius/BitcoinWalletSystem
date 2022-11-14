<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use App\Models\GeneralSetting;
use App\Models\Transaction;
use App\Models\Send;
use App\Models\UserWallet;
use App\Http\Controllers\Gateway\Blockio\BlockIo;
use Carbon\Carbon;

class CronController extends Controller
{

    public function rate(){
        try{
            $general = GeneralSetting::first();
            $apiKey = $general->api;
            $version = $general->api_version;
            $pin =  $general->pin;
            $block_io = new BlockIo($apiKey, $pin, $version);
            $result = $block_io->get_current_price(array('price_base' => 'USD'));
            $price = json_decode(json_encode($result),true)['data']['prices'][0]['price'];
            $general->usd_rate =$price;
            $general->save();

            return 'Price Updated!';

        }catch(\Exception $ex){
            return $ex->getMessage();
        }

        $this->lastCron($general, 'rate');

    }

    public function send(){

        $sendAll = Send::where('status', 0)->take(10)->get();
        $general = GeneralSetting::first();
        $apiKey = $general->api;
        $version = $general->api_version;
        $pin =  $general->pin;
        $block_io = new BlockIo($apiKey, $pin, $version);

        foreach($sendAll as $send){
            try{

                $response = $block_io->prepare_transaction(array('amounts' => $send->amount, 'to_addresses' => $send->receive_wallet));
                $send->status = 1;
                $send->save();

            }catch(\Exception $ex){
                $send->status = 9;
                $send->save();

                echo $ex->getMessage();
            }
        }

        $this->lastCron($general, 'send');

    }

    public function receive(){

        $wallets = UserWallet::orderBy('last_cron')->take(30)->get();
        $general = GeneralSetting::first();
        $apiKey = $general->api;
        $version = $general->api_version;
        $pin =  $general->pin;
        $block_io = new BlockIo($apiKey, $pin, $version);

        foreach($wallets as $wallet){

            echo $wallet->wallet_address.' - ';

            try{

                $response = $block_io->get_address_balance(array('addresses' => $wallet->wallet_address));
                $balance = json_decode(json_encode($response),true);
                $amount = $balance['data']['balances'][0]['available_balance'];

                if($amount > 0){
                    $move = $block_io->prepare_transaction(array('amounts' => $amount, 'from_addresses' => $wallet->wallet_address, 'to_addresses' => $general->wallet));

                    if($move){
                        $wallet->balance += $amount;

                        $transaction = new Transaction();
                        $transaction->user_id = $wallet->user_id;
                        $transaction->wallet_id = $wallet->id;
                        $transaction->amount = $amount;
                        $transaction->post_balance = $wallet->balance;
                        $transaction->charge = 0;
                        $transaction->trx_type = '+';
                        $transaction->details = 'Received '.$amount.' '.$general->cur_text;

                        $transaction->trx = getTrx();
                        $transaction->save();

                        notify($wallet->user, 'BAL_RECEIVE', [
                            'trx' => $transaction->trx,
                            'amount' => showAmount($amount, 8),
                            'currency' => $general->cur_text,
                            'post_balance' => showAmount($wallet->balance, 8),
                            'wallet' => $wallet->wallet_address,
                            'wallet_name' => $wallet->name ?? 'N/A'
                        ]);

                    }
                }

                $wallet->last_cron = time();
                $wallet->save();

                echo 'Updated<br><br>';

            }catch(\Exception $ex){
                echo $ex->getMessage().'<br><br>';
            }

        }

        $this->lastCron($general, 'receive');

    }

    protected function lastCron($general, $cronName){
        $crons = json_decode(@$general->last_cron);
        @$crons->$cronName = Carbon::now()->toDateTimeString();

        $general->last_cron = json_encode(@$crons);
        $general->save();
    }




}

