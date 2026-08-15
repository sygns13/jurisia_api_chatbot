<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio / WhatsApp
    |--------------------------------------------------------------------------
    |
    | Credenciales de Twilio y SID de las Content Templates usadas para enviar
    | listas interactivas (twilio/list-picker) por WhatsApp.
    |
    | Los SID son opcionales: si no estan configurados, el bot responde con el
    | listado en texto plano, tal como lo hacia antes.
    |
    | La cantidad de items de un list-picker se fija al crear la plantilla, por
    | lo que se registra un SID por cada cantidad de partes procesales posible.
    |
    */

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),

        'content' => [
            // Menu de tipos de consulta (5 opciones fijas, sin variables).
            'consultas' => env('TWILIO_CONTENT_SID_CONSULTAS'),

            // Seleccion de parte procesal, indexado por cantidad de partes.
            'partes' => [
                2 => env('TWILIO_CONTENT_SID_PARTES_2'),
                3 => env('TWILIO_CONTENT_SID_PARTES_3'),
                4 => env('TWILIO_CONTENT_SID_PARTES_4'),
                5 => env('TWILIO_CONTENT_SID_PARTES_5'),
            ],
        ],
    ],

];
