<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Entity;

use Nette;
use Phlox\EntityDatabase\Exception\EntityException;
use Phlox\EntityDatabase\Metadata\PropertyMeta;

class EntityHelpers
{

    /**
     * DateTime, Time, Date formats
     * @var string[]
     */
    protected static array $dateTimeFormats = [
        // DATETIME
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s.u\Z',
        DATE_ATOM,
        DATE_COOKIE,
        DATE_RFC1036,
        DATE_RFC1123,
        DATE_RFC2822,
        DATE_RFC3339,
        DATE_RFC822,
        DATE_RFC850,
        DATE_RSS,
        DATE_W3C,
        'Y-m-d H:i',
        // TIME
        'H:i:s',
        'H:i',
        'G:i:s',
        'G:i',
        'H:i:s.u',
        'H:i.u',
        'G:i:s.u',
        'G:i.u',
        // DATE
        'Y-m-d',
        'Y-n-j',
        'Y-m-dP'
    ];

    /**
     * Parses a date/datetime/time value from a string, trying $format first
     * and falling back to a list of common formats.
     *
     * @param string $value
     * @param string|null $format Prefer this format
     */
    public static function parseDateTime(string $value, ?string $format = null): ?Nette\Utils\DateTime
    {
        if ($format !== null) {
            $dt = Nette\Utils\DateTime::createFromFormat($format, $value);
            if ($dt) {
                return $dt;
            }
        }
        foreach (self::$dateTimeFormats as $fallbackFormat) {
            $dt = Nette\Utils\DateTime::createFromFormat($fallbackFormat, $value);
            if ($dt) {
                return $dt;
            }
        }
        throw new EntityException('Unable to parse DateTime string "' . $value . '"', EntityException::INVALID_FORMAT);
    }

    /**
     * Parses a MySQL SET column value ("read,write" or "" for none) into a list.
     *
     * @return string[]
     */
    public static function parseMysqlSet(string $raw): array
    {
        return $raw === '' ? [] : explode(',', $raw);
    }

    /**
     * Parses a Postgres array literal (e.g. '{1,2,3}' or '{"a,b","c"}') into a list.
     * Handles quoting/escaping for a single dimension; nested (multi-dimensional)
     * arrays are not supported — Postgres array columns are a niche enough case
     * that this covers the common usage without a full recursive parser.
     *
     * @return array<int, string|null>
     */
    public static function parsePgArray(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '{}') {
            return [];
        }
        $raw = substr($raw, 1, -1);

        $result   = [];
        $current  = '';
        $inQuotes = false;
        $len      = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inQuotes) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $raw[++$i];
                } elseif ($ch === '"') {
                    $inQuotes = false;
                } else {
                    $current .= $ch;
                }
                continue;
            }
            if ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $result[] = $current === 'NULL' ? null : $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $result[] = $current === 'NULL' ? null : $current;

        return $result;
    }

    /**
     * Normalizes a raw BIT/boolean column value into a real bool. Drivers
     * disagree on the wire representation (e.g. pdo_sqlsrv returns the
     * string '1'/'0' for BIT rather than casting it like it does MONEY or
     * DATETIME2), so this accepts whatever shape shows up in practice.
     */
    public static function parseBit(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            return $raw !== '' && $raw !== '0' && $raw !== "\0";
        }
        return (bool) $raw;
    }

    /**
     * Reverses the get-hook decoding (json_decode / parseMysqlSet / parsePgArray
     * — see PhpEntityRenderer, which generates the matching get-hook branch for
     * each of these) so a modified property value round-trips back into the
     * raw representation its DB column expects, ready for Entity::saveModified().
     */
    public static function encodeForStorage(PropertyMeta $propMeta, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($propMeta->json !== null) {
            return json_encode($value);
        }

        if ($propMeta->nativeType === 'set' && is_array($value)) {
            return implode(',', $value);
        }

        if ($propMeta->nativeType !== null && str_starts_with($propMeta->nativeType, '_') && is_array($value)) {
            $escaped = array_map(
                fn($v) => $v === null ? 'NULL' : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
                $value,
            );
            return '{' . implode(',', $escaped) . '}';
        }

        return $value;
    }

}
