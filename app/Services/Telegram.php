<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Telegram
{
    public static function configured(): bool
    {
        return (string) config('homecam.telegram_bot_token') !== '' && (string) config('homecam.telegram_chat_id') !== '';
    }

    /** Send a message to the configured group. Returns error text or null on success. */
    public static function send(string $text): ?string
    {
        if (!self::configured()) {
            return 'Telegram не настроен: заполните TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в .env';
        }
        try {
            $http = Http::timeout(20)->asForm();
            if ($proxy = (string) config('homecam.telegram_proxy')) {
                $http = $http->withOptions(['proxy' => $proxy]);
            }
            $r = $http->post(
                'https://api.telegram.org/bot' . config('homecam.telegram_bot_token') . '/sendMessage',
                [
                    'chat_id' => config('homecam.telegram_chat_id'),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => 'true',
                ]
            );
            if (!$r->successful()) {
                $err = $r->json('description') ?: ('HTTP ' . $r->status());
                Log::warning('Telegram send failed: ' . $err);
                return $err;
            }
            return null;
        } catch (\Throwable $e) {
            // never leak the bot token into logs or the UI
            $msg = str_replace((string) config('homecam.telegram_bot_token'), '***', $e->getMessage());
            Log::warning('Telegram send failed: ' . $msg);
            return $msg;
        }
    }
}
