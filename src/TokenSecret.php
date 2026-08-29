<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

/**
 * The secret behind the bridge's placeholder tokens.
 *
 * EvoSyntaxBridge names its tokens after a truncated HMAC, so that a value
 * rendered through Latte cannot spell a token and be turned back into live
 * EVO syntax. That only works while the key is unguessable.
 *
 * It is the plugin's own key and nothing else's. Reusing the application key
 * would mean publishing a 64-bit truncation of an HMAC of it inside every
 * compiled template - a needless exposure of the one secret the whole site
 * depends on. This key protects one thing, is used for one thing, and is worth
 * nothing anywhere else.
 *
 * It also lives in a file rather than in system_settings, because a setting is
 * readable from any template or chunk as [(setting_name)] - which is precisely
 * the class of output this key must never reach.
 *
 * Stability matters as much as secrecy: the token prefix is derived from the
 * template plus this key, so a key that changed between requests would change
 * every compiled template's cache id and recompile the whole site on every
 * hit. Hence a persisted file, read once per process.
 */
final class TokenSecret
{
    private static ?string $secret = null;

    /** 64 hex characters, stable for the life of the installation. */
    public static function get(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        foreach (self::candidatePaths() as $path) {
            $secret = self::readOrCreate($path);

            if ($secret !== null) {
                return self::$secret = $secret;
            }
        }

        // Nowhere writable. Still safe - the tokens are unguessable - but the
        // key changes per process, so Latte recompiles each template once per
        // worker rather than once per deployment.
        return self::$secret = bin2hex(random_bytes(32));
    }

    /** Forget the cached value. Tests only. */
    public static function reset(): void
    {
        self::$secret = null;
    }

    /** @return list<string> */
    private static function candidatePaths(): array
    {
        $paths = [];

        if (function_exists('storage_path')) {
            $paths[] = rtrim(str_replace('\\', '/', storage_path('alattex')), '/') . '/token.key';
        }

        $paths[] = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/alattex-token.key';

        return $paths;
    }

    private static function readOrCreate(string $path): ?string
    {
        $existing = self::read($path);

        if ($existing !== null) {
            return $existing;
        }

        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return null;
        }

        $secret = bin2hex(random_bytes(32));

        // Written through a temporary file in the same directory so that two
        // requests racing to create it cannot leave a half-written key behind;
        // whichever rename lands last wins, and both are valid keys.
        $tmp = $path . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $secret, LOCK_EX) === false) {
            return null;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            // Someone else may have won the race; take theirs if it is valid.
            return self::read($path);
        }

        return $secret;
    }

    /**
     * The key at $path, or null if there is not a valid one there.
     *
     * Guarded with is_file()/is_readable() rather than a suppressed read: `@`
     * still reaches a registered error handler, and PHPUnit's turns the
     * resulting warning into a failed test.
     */
    private static function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $contents = trim($contents);

        return strlen($contents) === 64 && ctype_xdigit($contents) ? $contents : null;
    }
}
