<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnepayController extends Controller
{

    public $api_key = '4896aa550a77654344cc8d4b16f253cc';
    public $merchant_no = '071588';
    public $sign_type = 'MD5';
    public $orderUrl = 'https://api.star-pay.vip/api/gateway/pay';
    public $orderTraceUrl = 'https://api.star-pay.vip/api/gateway/batch-query/order';
    public $paymentUrl = 'https://api.star-pay.vip/api/gateway/withdraw';

    public function getPost($url, $requestJson)
    {
        $ch = curl_init();//initialization
        curl_setopt($ch, CURLOPT_URL, $url);//Visited URL
        curl_setopt($ch, CURLOPT_POST, true);//The request method is post request
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//Only get the page content, but do not output it
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//https Request Do not verify certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//https Request Do not verify HOST
        $header = [
            'Content-type: application/json;charset=UTF-8',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //Simulated header
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);//Request data
        $result = curl_exec($ch);//Execute request
        curl_close($ch);//Close curl and release resources


        return $result;
    }

    //-----------------Deposit System
    public function onlinePay($amount)
    {
        if ($amount == '' || !is_numeric($amount)) {
            return response()->json(['status' => false, 'message' => 'Enter correct amount', 'url' => '']);
        }

        $afterRequest = $this->createOrder($amount);

        $mainResponse = json_decode($afterRequest, true);
        $params = json_decode($mainResponse['params'], true);

        $responseCode = $mainResponse['code'];
        $timestamp = $mainResponse['timestamp'];

        if ($responseCode == 200) {
            //Params Data
            $orderId = $params['merchant_ref'];
            $systemOrderId = $params['system_ref'];
            $payToAddress = $params['to'];
            $pay_amount = $params['pay_amount'];
            $fee = $params['fee'];
            $depoType = $params['product'];
            $status = $params['status']; //0
            $payurl = $params['payurl']; // for redirect

            $model = new Deposit();
            $model->user_id = auth()->id();
            $model->order_id = $orderId;
            $model->system_order_id = $systemOrderId;
            $model->amount = $pay_amount;
            $model->system_fee = $fee;
            $model->pay_address = $payToAddress;
            $model->pay_type = $depoType;
            $model->timestamp = $timestamp;
            $model->status = $status == 0 ? 'pending' : 'rejected';
            $model->date = now();
            $model->save();

            return response()->json(['status' => true, 'message' => 'Waiting for redirecting...', 'url' => $payurl]);
        } else {
            return response()->json(['status' => false, 'message' => 'The system error refresh again.', 'url' => '']);
        }
    }


    public function createOrder($amount)
    {
        $url = $this->orderUrl;
        $api_key = $this->api_key;
        $merchant_ref = rand(0, 9999) . rand(0, 9999) . rand(0, 9999) . rand(0, 9999);
        $merchant_no = $this->merchant_no;
        $sign_type = $this->sign_type;
        $key = $api_key; // The key for the encryption
        $timestamp = time();

        // The params array
        $params = array(
            'merchant_ref' => $merchant_ref,
            'product' => 'USDT-TRC20Deposit',
            'amount' => $amount,
            'extra' => array(
                'KBANK',
                'account_no' => $merchant_no,
            ),
            "language" => "th_TH"
        );

        // Encode the params array to a JSON string
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

        // Concatenate the values in the specified order
        $stringToBeSigned = $merchant_no . $paramsJson . $sign_type . $timestamp . $key;

        // Perform MD5 encryption on the concatenated string
        //Sign
        $md5Hash = md5($stringToBeSigned);


        // Create the final request array
        $request = array(
            "merchant_no" => $merchant_no,
            "timestamp" => $timestamp,
            "sign_type" => $sign_type,
            "params" => $paramsJson,
            "sign" => $md5Hash
        );


        // Encode the final request array to JSON string
        $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE);


        $ch = curl_init();//initialization
        curl_setopt($ch, CURLOPT_URL, $url);//Visited URL
        curl_setopt($ch, CURLOPT_POST, true);//The request method is post request
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//Only get the page content, but do not output it
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//https Request Do not verify certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//https Request Do not verify HOST
        $header = [
            'Content-type: application/json;charset=UTF-8',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //Simulated header
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);//Request data
        $result = curl_exec($ch);//Execute request
        curl_close($ch);//Close curl and release resources


        return $result;
    }

    public function orderTrace()
    {
        $deposits = Deposit::where('user_id', auth()->id())->where('created_at', 'like', date("Y-m-d") . "%")->where('status', 'pending')->get();

        foreach ($deposits as $deposit) {
            $url = $this->orderTraceUrl;
            $api_key = $this->api_key;
            $merchant_ref = $deposit->order_id;
            $merchant_no = $this->merchant_no;
            $sign_type = $this->sign_type;
            $key = $api_key; // The key for the encryption
            $timestamp = time();

            // The params array
            $params = array(
                'merchant_refs' => [$merchant_ref],
            );

            // Encode the params array to a JSON string
            $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

            // Concatenate the values in the specified order
            $stringToBeSigned = $merchant_no . $paramsJson . $sign_type . $timestamp . $key;

            // Perform MD5 encryption on the concatenated string
            //Sign
            $md5Hash = md5($stringToBeSigned);


            // Create the final request array
            $request = array(
                "merchant_no" => $merchant_no,
                "timestamp" => $timestamp,
                "sign_type" => $sign_type,
                "params" => $paramsJson,
                "sign" => $md5Hash
            );


            // Encode the final request array to JSON string
            $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE);


            $afterTrace = $this->getPost($url, $requestJson);
            $actualData = json_decode($afterTrace, true);

            if ($actualData['code'] == 200) {
                $actualData = json_decode($actualData['params'], true);

                if ($actualData[0]['status'] == 1) {
                    $deposit->amount = $actualData[0]['pay_amount'];
                    $deposit->status = $actualData[0]['status'] == 1 ? 'approved' : 'pending';
                    $deposit->save();

                    $user = User::where('id', $deposit->user_id)->first();
                    $user->balance = $user->balance + $actualData[0]['pay_amount'];
                    $user->investor = 1;
                    $user->save();
                }
            }
        }

        return true;
    }


    //-----------------------Withdrawal System
    public function paymentOrder($withdrawal_id)
    {
        $withdrawal = Withdrawal::where('id', $withdrawal_id)->where('status', 'pending')->first();
        if ($withdrawal) {
            $url = $this->paymentUrl;
            $api_key = $this->api_key;
            $merchant_ref = $withdrawal->oid;
            $merchant_no = $this->merchant_no;
            $sign_type = $this->sign_type;
            $key = $api_key; // The key for the encryption
            $timestamp = time();

            // The params array
            $params = array(
                'merchant_ref' => $merchant_ref,
                'product' => 'USDT-TRC20Payout',
                'amount' => $withdrawal->final_amount,
                'extra' => array(
                    'account_name' => 'Binance',
                    'address' => $withdrawal->address,
                ),
            );

            // Encode the params array to a JSON string
            $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

            // Concatenate the values in the specified order
            $stringToBeSigned = $merchant_no . $paramsJson . $sign_type . $timestamp . $key;

            // Perform MD5 encryption on the concatenated string
            //Sign
            $md5Hash = md5($stringToBeSigned);


            // Create the final request array
            $request = array(
                "merchant_no" => $merchant_no,
                "timestamp" => $timestamp,
                "sign_type" => $sign_type,
                "params" => $paramsJson,
                "sign" => $md5Hash
            );


            // Encode the final request array to JSON string
            $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE);


            $ch = curl_init();//initialization
            curl_setopt($ch, CURLOPT_URL, $url);//Visited URL
            curl_setopt($ch, CURLOPT_POST, true);//The request method is post request
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//Only get the page content, but do not output it
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//https Request Do not verify certificate
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//https Request Do not verify HOST
            $header = [
                'Content-type: application/json;charset=UTF-8',
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //Simulated header
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);//Request data
            $result = curl_exec($ch);//Execute request
            curl_close($ch);//Close curl and release resources

            return json_decode($result, true);
        } else {
            return false;
        }
    }

    //----------------------Trace
    public function traceFinance()
    {
        $this->orderTrace();
        return "Checked";
    }





    public function merchantBalanceQuery()
    {
        $api_key = $this->api_key;
        $merchant_no = $this->merchant_no;
        $sign_type = $this->sign_type;
        $key = $api_key; // The key for the encryption
        $timestamp = time();

        // The params array
        $params = array(
            'currency' => 'USDT',
        );

        // Encode the params array to a JSON string
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

        // Concatenate the values in the specified order
        $stringToBeSigned = $merchant_no . $paramsJson . $sign_type . $timestamp . $key;

        // Perform MD5 encryption on the concatenated string
        //Sign
        $md5Hash = md5($stringToBeSigned);


        // Create the final request array
        $request = array(
            "merchant_no" => $merchant_no,
            "timestamp" => $timestamp,
            "sign_type" => $sign_type,
            "params" => $paramsJson,
            "sign" => $md5Hash
        );


        // Encode the final request array to JSON string
        $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE);


        $ch = curl_init();//initialization
        curl_setopt($ch, CURLOPT_URL, 'https://api.star-pay.vip/api/gateway/query/balance');//Visited URL
        curl_setopt($ch, CURLOPT_POST, true);//The request method is post request
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//Only get the page content, but do not output it
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//https Request Do not verify certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//https Request Do not verify HOST
        $header = [
            'Content-type: application/json;charset=UTF-8',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //Simulated header
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);//Request data
        $result = curl_exec($ch);//Execute request
        curl_close($ch);//Close curl and release resources

        return json_decode($result, true);
    }

}
