<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
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
        return self::call('sendMessage', fn (PendingRequest $http, string $url) => $http->asForm()->post($url, [
            'chat_id' => config('homecam.telegram_chat_id'),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]));
    }

    /** Send a picture with a caption (HTML). */
    public static function sendPhoto(string $file, string $caption): ?string
    {
        return self::call('sendPhoto', fn (PendingRequest $http, string $url) => $http
            ->attach('photo', file_get_contents($file), 'motion.jpg')
            ->post($url, [
                'chat_id' => config('homecam.telegram_chat_id'),
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]));
    }

    private static function call(string $method, callable $send): ?string
    {
        if (!self::configured()) {
            return 'Telegram не настроен: заполните TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в .env';
        }
        try {
            $http = Http::timeout(30);
            if ($proxy = (string) config('homecam.telegram_proxy')) {
                $http = $http->withOptions(['proxy' => $proxy]);
            }
            $r = $send($http, 'https://api.telegram.org/bot' . config('homecam.telegram_bot_token') . '/' . $method);
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
