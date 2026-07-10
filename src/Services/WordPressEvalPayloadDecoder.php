<?php

namespace hexa_package_wordpress\Services;

class WordPressEvalPayloadDecoder
{
    public function decode(string $output, string $marker): ?array
    {
        if ($marker === '') {
            return null;
        }

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            $position = strpos($line, $marker);
            if ($line === '' || $position === false) {
                continue;
            }

            $decoded = json_decode(trim(substr($line, $position + strlen($marker))), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
