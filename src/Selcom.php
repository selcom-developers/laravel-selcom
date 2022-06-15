<?php

namespace JasiriLabs\Selcom;

use Illuminate\Support\Facades\Http;


class Selcom
{
    
    protected array $config;
    

    public function __construct(array $config)
    {
        $this->config = $config['laravel-selcom'];
    }


    public function computeSignature($data, $signed_fields, $requestTimestamp): string
    {
        $fieldsOrder = explode(',', $signed_fields);
        $signData = "timestamp=$requestTimestamp";

        foreach ($fieldsOrder as $key) {
            $signData .= "&$key=" . $data[$key];
        }

        return base64_encode(hash_hmac('sha256', $signData, $this->config['api_secret'], true));
    }


    public function sendPostRequest($url, array $data): array
    {
        date_default_timezone_set('Africa/Dar_es_Salaam');
        $requestTimestamp = date('c');

        $endpointUrl = $this->config['base_url'] . $url;

        $signed_fields  = implode(',', array_keys($data));

        $response = Http::withHeaders([
            'Content-Type' => 'application/json;charset=\"utf-8\"',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Authorization' => 'SELCOM ' . base64_encode($this->config['api_key']),
            'Digest-Method' => 'HS256',
            'Digest' => $this->computeSignature($data, $signed_fields, $requestTimestamp),
            'Timestamp' => $requestTimestamp,
            'Signed-Fields' => $signed_fields,
        ])->post($endpointUrl, $data);

        $paymentGatewayUrl = base64_decode(json_decode($response->body(), true)['data'][0]['payment_gateway_url']);

        return [
            'payment_gateway_url' => $paymentGatewayUrl,
        ];
    }

}
