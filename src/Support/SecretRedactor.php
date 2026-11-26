<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Keeps passwords, OTPs, 2FA codes, API credentials and message bodies out of logs and audit
 * records (GLI-33, PQPL-7871): values are replaced by a fixed marker, never partially masked.
 *
 * Shared by betmaker and backoffice; the global redact_secrets() / redact_url_query() /
 * redact_secret_keys() helpers in src/Helpers/helpers.php are thin wrappers around it.
 */
class SecretRedactor
{
    public const MARKER = '[REDACTED]';

    /**
     * Lower-case key names whose values are never logged. 'token' is deliberately absent: the
     * password-recovery flows use it as a correlation id.
     */
    public const KEYS = [
        'password', 'pass', 'pw', 'passwd', 'realpass',
        'new_password', 'old_password', 'current_password',
        'password_confirmation', 'new_password_confirmation', 'confirm_password',
        'confirmpassword', 'newpassword', 'oldpassword', 'currentpassword',
        'login_password',
        'otp', 'code', 'one_time_password', 'verification_code',
        'client_secret', 'secret', 'google2fa_secret',
        'api_key', 'apikey', 'api_token', 'apitoken', 'access_token', 'refresh_token', 'authorization',
        'pin',
        'msg', 'message', 'messagetext', 'sms', 'text', 'content', 'mensaje',
    ];

    /**
     * Recursively replaces the value of every sensitive key. Objects are walked as arrays;
     * strings and other scalars come back unchanged.
     *
     * @param mixed    $data
     * @param string[] $extraKeys more key names (any case) for this call only, e.g. a provider
     *                            that sends the SMS text as 'body'
     * @return mixed
     */
    public static function redact($data, array $extraKeys = [])
    {
        if (is_object($data)) {
            if ($data instanceof Arrayable) {
                $data = $data->toArray();
            } elseif ($data instanceof JsonSerializable) {
                $data = json_decode((string) json_encode($data), true);
            } else {
                $data = get_object_vars($data);
            }
        }

        if (!is_array($data)) {
            return $data;
        }

        $sensitive = array_merge(self::KEYS, array_map('strtolower', $extraKeys));
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $result[$key] = self::MARKER;
            } elseif (is_array($value) || is_object($value)) {
                $result[$key] = self::redact($value, $extraKeys);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Replaces the value of every sensitive query parameter in a URL, or in free text that
     * embeds one (e.g. a Guzzle exception message quoting the request line). Parameters such as
     * password[] or msg[0] count as their base name.
     *
     * @param mixed $url
     */
    public static function redactUrlQuery($url, array $extraKeys = []): string
    {
        $url = (string) $url;

        if (strpos($url, '=') === false) {
            return $url;
        }

        $sensitive = array_merge(self::KEYS, array_map('strtolower', $extraKeys));

        return (string) preg_replace_callback(
            '/(^|[?&;\s`(])([A-Za-z0-9_.\-%\[\]]+)=([^&;#\s`]*)/',
            static function (array $m) use ($sensitive): string {
                $name = strtolower(urldecode($m[2]));
                $base = (string) preg_replace('/\[.*$/', '', $name);

                return in_array($name, $sensitive, true) || in_array($base, $sensitive, true)
                    ? $m[1] . $m[2] . '=' . self::MARKER
                    : $m[0];
            },
            $url
        );
    }
}
