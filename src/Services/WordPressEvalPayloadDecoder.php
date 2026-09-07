<?php

namespace hexa_package_wordpress\Services;

class WordPressEvalPayloadDecoder
{
    public function decode(string $output, string $marker): ?array
    {
        if ($marker === '') {
            return null;
        }

        $offset = 0;
        while (($markerPosition = strpos($output, $marker, $offset)) !== false) {
            $payloadStart = $markerPosition + strlen($marker);
            $nextMarker = strpos($output, $marker, $payloadStart);
            $searchEnd = $nextMarker === false ? strlen($output) : $nextMarker;

            for ($position = $payloadStart; $position < $searchEnd; $position++) {
                if ($output[$position] !== '{' && $output[$position] !== '[') {
                    continue;
                }

                $candidate = $this->balancedJsonAt($output, $position, $searchEnd);
                if ($candidate === null) {
                    continue;
                }

                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            $offset = $payloadStart;
        }

        return null;
    }

    private function balancedJsonAt(string $output, int $start, int $limit): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;

        for ($position = $start; $position < $limit; $position++) {
            $character = $output[$position];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
                continue;
            }
            if ($character === '{' || $character === '[') {
                $stack[] = $character;
                continue;
            }
            if ($character !== '}' && $character !== ']') {
                continue;
            }

            $expectedOpening = $character === '}' ? '{' : '[';
            if (array_pop($stack) !== $expectedOpening) {
                return null;
            }
            if ($stack === []) {
                return substr($output, $start, $position - $start + 1);
            }
        }

        return null;
    }
}
