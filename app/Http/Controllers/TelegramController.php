<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramController extends Controller
{
    public function handle(Request $request)
    {
        $update = Telegram::getWebhookUpdate();

        // Aquí procesas la lógica de tu bot
        // Por ejemplo, puedes obtener el mensaje y el chat_id
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();
        $text = $message->getText();

        // Aquí es donde te comunicarías con tu propia API de Laravel
        // para obtener una respuesta y enviarla de vuelta a Telegram.
        // Por ejemplo:
        // $responseFromApi = $this->callMyApi($text);

        Telegram::sendMessage([
            'chat_id' => $chat_id,
            'text' => 'He recibido tu mensaje: ' . $text. ' Con el chat_id: ' . $chat_id,
        ]);

        return response()->json(['status' => 'success']);
    }
}
