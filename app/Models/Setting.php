<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    /** Setting from DB, falling back to config/homecam.php (which reads .env). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);
        if ($row !== null && $row->value !== null && $row->value !== '') {
            return $row->value;
        }
        return config('homecam.' . $key, $default);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ($value === null || $value === '') ? null : (string) $value]);
    }
}
