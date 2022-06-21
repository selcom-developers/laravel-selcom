<?php

/*
 * You can place your custom package configuration in here.
 */
return [
      
      /**
      * To work with Selcom's  API you'll need some credentials.
      *
      * If you don't have credentials yet, head over to selcom customer support 
     */

      'base_url' => env('SELCOM_BASE_URL', 'https://apigw.selcommobile.com/v1'),


      
      'api_key' => env('SELCOM_API_KEY'),


      
      'api_secret' => env('SELCOM_API_SECRET_KEY'),

];