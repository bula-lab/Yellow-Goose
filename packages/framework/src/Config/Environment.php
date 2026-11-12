<?php
namespace ZoltaGaska\Config;

final class Environment
{
    /** Literal .env values; existing process variables always win. */
    public static function load(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/iD', $line, $match)) {
                throw new \RuntimeException('Invalid .env entry on line ' . ($index + 1));
            }
            [, $name, $value] = $match;
            if (getenv($name) !== false) continue;
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $end = strpos($value, $value[0], 1);
                if ($end === false || !preg_match('/^\s*(#.*)?$/D', substr($value, $end + 1))) {
                    throw new \RuntimeException('Invalid quoted .env value on line ' . ($index + 1));
                }
                $value = substr($value, 1, $end - 1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            putenv($name . '=' . $value);
        }
    }
}
