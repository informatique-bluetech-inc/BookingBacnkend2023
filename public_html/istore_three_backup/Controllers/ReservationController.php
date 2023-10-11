<?php
namespace Controllers;


require_once __DIR__."/../Helpers/LogMsg.php";
use Helpers\LogMsg;

use DateInterval;
use DatePeriod;
use DateTime;
use Models\ConfigModel;

class ReservationController
{


    static public function fetchAvailableSlots($device_type): array
    {
        AuthController::validateToken();

        $config = new ConfigModel();
        $url = $config->REST_BASE_URL . $config->REST_GSX_PATH . "/reservation/fetch-available-slots?productCode=" . $device_type;

        $request_headers = [
            'X-Apple-SoldTo: ' . $config->REST_SoldTo,
            'X-Apple-ShipTo: ' . $config->REST_ShipTo,
            'X-Apple-Auth-Token: ' . $config->REST_AUTH_TOKEN,
            'X-Apple-Service-Version: v5',
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Apple-Client-Locale: en-US'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSLCERT, $config->REST_CERT_PATH);
        curl_setopt($ch, CURLOPT_SSLKEY, $config->REST_SLL_KEY);
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $config->REST_CERT_PASS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($statusCode == 200 || $statusCode == 201) {
            return [$ch, $statusCode, json_decode($result)];
        } else {
            return [$ch, $statusCode, false];
        }

    }

    static public function timeAvailableSlots($device_type, $filter_date): void
    {
        $slots = self::fetchAvailableSlots($device_type);
      
        if (!($slots[1] == 200 || $slots[1] == 201)) {
            $response = [
                "status" => $slots[1],
                "response" => "Tienes problemas con la firma de apple."
            ];
            echo json_encode($response);
            return;
        }
        
        $hours_available = [];
        date_default_timezone_set((string) $slots[2]->storeTimeZone);

        foreach ($slots[2]->slots as $item) {//iterates every available datetime
            $startDatetimeUtcTimezone = strtotime ($item->start);
            $startDatetimeLocalTimezone = date('Y-m-d H:i:s', $startDatetimeUtcTimezone);
            
            if(date('Y-m-d', strtotime($startDatetimeLocalTimezone)) !=  $filter_date){
                continue;//if it is not date we are looking for, jump this iteration
            }
            if($item->reservationType != "CIN"){
                continue;//if the type is not cin, jump this iteration
            }
            
            $hours_available[] = date('H:i', strtotime($startDatetimeLocalTimezone));
        }

        sort($hours_available);

        if (!is_null($hours_available)) {
            echo json_encode(["correlationId" => $slots[2]->correlationId, "hours" => $hours_available]);
        } else {
            echo [];
        }
    }


    static public function dateAvailableSlots($device_type): void
    {
        $slots = self::fetchAvailableSlots($device_type);
   
        if (!($slots[1] == 200 || $slots[1] == 201)) {
            $response = [
                "status" => $slots[1],
                "response" => "Tienes problemas con la firma de apple."
            ];
            echo json_encode($response);
            return;
        }


        $arr = [];
        $days = [];
        $days_period = [];
        $days_unavaibles = [];

        foreach ($slots[2]->slots as $listDate) {
            $arr[] = date('Y-m-d', strtotime($listDate->start . " UTC"));
        }

        foreach (array_unique($arr) as $key => $value) {
            //echo $value->format('Y-m-d').'<br>';
            $days[] = $value;
        }

        $startDate = time();

        $period = new DatePeriod(
            new DateTime('2022-02-01'),
            //new DateTime(date('Y-m-d', strtotime('-1 day', $startDate))),
            new DateInterval('P1D'),
            //new DateTime('2021-06-30')
            new DateTime(date('Y-m-d', strtotime('+20 day', $startDate)))
        );


        foreach ($period as $key => $value) {
            $days_period[] = $value->format('Y-m-d');
        }

        foreach ($days_period as $key => $value) {
            if (!in_array($value, $days)) {
                $days_unavaibles[] = $value;
            }
        }

        echo json_encode(["correlationId" => $slots[2]->correlationId, "days_unavaibles" => $days_unavaibles]);
        curl_close($slots[0]);
    }

    static public function create(): void
    {
        LogMsg::message("Started create() function ");
        AuthController::validateToken();

        $config = new ConfigModel();

        $request = json_decode(file_get_contents('php://input'), true);
        LogMsg::message("Request = ".$request);
        $url = $config->REST_BASE_URL . $config->REST_GSX_PATH . "/reservation/create";

        $date_appointment = date("Y-m-d\TH:i:s.000\Z", strtotime($request["appointment"] . " +4 hours"));

        if ($request['language'] == 'es-Es') {
            $lang_code = "es-ES";
        } else if ($request['language'] == 'fr-FR') {
            $lang_code = "fr-FR";
        } else {
            $lang_code = "en-US";
        }

        $request_headers = [
            'X-Apple-SoldTo: ' . $config->REST_SoldTo,
            'X-Apple-ShipTo: ' . $config->REST_ShipTo,
            'X-Apple-Auth-Token: ' . $config->REST_AUTH_TOKEN,
            'X-Apple-Service-Version: v5',
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Apple-Client-Locale: en-US'
        ];
        LogMsg::message("Request headers = ".$request_headers);

        $postData ='
                    {
                    "product": {
                        "issueReported": "'. $request["issue"] .'",
                        "productCode": "'. $request["device"] .'"
                    },
                    "notes": {
                        "note": "Booking Reservation IStore"
                    },
                    "emailLanguageCode": "'.$lang_code.'",
                    "shipToCode": "'. $config->REST_ShipTo .'",
                    "reservationType": "CIN",
                    "correlationId": "12345",
                    "reservationDate": "'.$date_appointment.'",
                    "device": {
                        "id": "'.$request["serial"].'"
                    },
                    "customer": {
                        "firstName": "'.$request["firstName"].'",
                        "lastName": "'.$request["lastName"].'",
                        "address": {
                        "line4": "",
                        "city": "",
                        "countryCode": "",
                        "postalCode": "",
                        "county": "",
                        "stateCode": "",
                        "line3": "",
                        "line2": "",
                        "line1": ""
                        },
                        "phone": {
                        "phoneCountryCd": "CA",
                        "primaryPhone": "'.$request["phoneNumber"] .'"
                        },
                        "emailId": "'. $request["email"].'",
                        "governmentId": ""
                    }
                    }';
        LogMsg::message("Post data = ".$postData);


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSLCERT, $config->REST_CERT_PATH);
        curl_setopt($ch, CURLOPT_SSLKEY, $config->REST_SLL_KEY);
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $config->REST_CERT_PASS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        
        $result = curl_exec($ch);
        
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = json_decode($result);
        LogMsg::message("data response from apple = ".$data);
      
        if ($result === false) {
            echo 'Curl error: ' . curl_error($ch);
        } else {
            $response = [
                "status" => $statusCode,
                "response" => $data
            ];
            echo json_encode($response);
        }
        curl_close($ch);
    }
}