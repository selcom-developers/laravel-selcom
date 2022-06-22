<?php

namespace JasiriLabs\Selcom;

use Illuminate\Support\Facades\Http;

class SelcomClient
{


    protected array $config;
    

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function get(string $url, array $params = [])
    {
        date_default_timezone_set('Africa/Dar_es_Salaam');
        $requestTimestamp = date('c');

        $endpointUrl = $this->config['base_url'] . $url;

        $signed_fields  = implode(',', array_keys($params));


        return Http::withHeaders([
            'Content-Type' => 'application/json;charset=\"utf-8\"',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Authorization' => 'SELCOM ' . base64_encode($this->config['api_key']),
            'Digest-Method' => 'HS256',
            'Digest' => $this->computeSignature($params, $signed_fields, $requestTimestamp),
            'Timestamp' => $requestTimestamp,
            'Signed-Fields' => $signed_fields,
        ])->get($this->buildUrl($url, $params));
    }


    /**
     * @throws \Exception
     */
    public function post(string $url, array $params)
    {
		date_default_timezone_set('Africa/Dar_es_Salaam');
		$requestTimestamp = date('c');

		$endpointUrl = $this->config['base_url'] . $url;

		$signed_fields  = implode(',', array_keys($params));

        $response = Http::withHeaders([
            'Content-Type' => 'application/json;charset=\"utf-8\"',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Authorization' => 'SELCOM ' . base64_encode($this->config['api_key']),
            'Digest-Method' => 'HS256',
            'Digest' => $this->computeSignature($params, $signed_fields, $requestTimestamp),
            'Timestamp' => $requestTimestamp,
            'Signed-Fields' => $signed_fields,
        ])->post($endpointUrl, $params);


        $responseBody =  json_decode($response->body(), true);

        if ($responseBody['resultcode'] != '000')
        {
            throw new \Exception($responseBody['message']);
        }

        return $responseBody;

	}

    public function delete(string $url, array $params = [])
    {
        date_default_timezone_set('Africa/Dar_es_Salaam');
        $requestTimestamp = date('c');

        $endpointUrl = $this->config['base_url'] . $url;

        $signed_fields  = implode(',', array_keys($params));

        return Http::withHeaders([
            'Content-Type' => 'application/json;charset=\"utf-8\"',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Authorization' => 'SELCOM ' . base64_encode($this->config['api_key']),
            'Digest-Method' => 'HS256',
            'Digest' => $this->computeSignature($params, $signed_fields, $requestTimestamp),
            'Timestamp' => $requestTimestamp,
            'Signed-Fields' => $signed_fields,
        ])->delete($this->buildUrl($url, $params));
    }


    private function buildUrl(string $url, array $params): string
    {
        $query = http_build_query($params);

        return $url . '?' . $query;
    }


	private function computeSignature($params, $signed_fields, $requestTimestamp): string
	{
		$fieldsOrder = explode(',', $signed_fields);
		$signParams = "timestamp=$requestTimestamp";

		foreach ($fieldsOrder as $key) {
			$signParams .= "&$key=" . $params[$key];
		}

		return base64_encode(hash_hmac('sha256', $signParams, $this->config['api_secret'], true));
	}
}
