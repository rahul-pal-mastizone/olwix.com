<?php

namespace RachidLaasri\LaravelInstaller\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Session;
use SebastianBergmann\Environment\Console;

class LicenseController extends Controller
{

    public function __construct()
    {

    }

    /**
     * Display the permissions check page.
     *
     * @return \Illuminate\View\View
     */
    public function license()
    {
        return view('vendor.installer.license');
    }

    public function licenseCheck(Request $request) {

        
        $request->validate([
            'email' => 'required',
            'username' => 'required',
            'purchase_code' => 'required'
        ]);

        $itemid = 33771074;
        $itemname = 'OmniMart';
        $domain = $request->getHost();


        try {
            $client = new Client();
            $response = $client->request('GET', 'https://api.envato.com/v3/market/author/sale?code='.$request->purchase_code, [
                'headers' => [
                    'content-type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer xXQiElDjx4Eb1Ed6v7ZPi4vXeGoFgWhX'
                ]
            ]);

            $responseBody = $response->getBody()->getContents();

            $formattedRes = json_decode($responseBody, true);

            $buyerUsername = $formattedRes['buyer'];


            if ($request->username != $buyerUsername || $itemid != $formattedRes['item']['id']) {
                Session::flash('license_error', 'Username / Purchase code didn\'t match for this item!');
                return redirect()->back();
            }


            $gdp = new Client();
            $gdpr = $gdp->request('GET', 'https://support.geniusdevs.com/api/clients/key-used/'.$request->purchase_code, [
                'headers' => [
                    'content-type' => 'application/json',
                    'Accept' => 'application/json'
                ]
            ]);

            $gdprd = $gdpr->getBody()->getContents();
            $gdprdjd = json_decode($gdprd, true);



            $rutl = array();

            foreach($gdprdjd['data'] as $data){
                if(URL::to('/') == $data['domin_url'] || $domain == $data['domin_url']){

                }else{
                    array_push($rutl, $data['domin_url']);
                }
            }

            if(count($rutl) >= 1){
                Session::flash('domin_url', $rutl);

                return redirect()->back();
            }

            fopen("core/vendor/mockery/mockery/verified", "w");

           
            if(in_array($domain, $gdprdjd['data']) || in_array(URL::to('/'), $gdprdjd['data'])){

            }else{
                $client->request('POST', 'https://support.geniusdevs.com/api/clients/store', [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ],
                    'form_params' => [
                        'item_name' => $itemname,
                        'item_id' => $itemid,
                        'email' => $request->email,
                        'envato_username' => $request->username,
                        'purchase_code' => $request->purchase_code,
                        'domin_url' => $domain,
                    ]
                ]);
            }
            


            Session::flash('license_success', 'Your license is verified successfully!');
           
            return redirect()->route('LaravelInstaller::environmentWizard');
            

        } catch (\Exception $e) {
            Session::flash('license_error', 'Something went wrong!');
            return redirect()->back();
        }

    }
}
