<?php

declare(strict_types=1);

namespace App\Platform\Support;

/**
 * TXT records for a hostname. A class rather than a bare dns_get_record()
 * call so tests can answer for DNS.
 */
class DnsTxtLookup
{
    /** @return list<string> */
    public function txt(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);

        return array_values(array_filter(array_map(
            fn (array $record) => $record['txt'] ?? implode('', $record['entries'] ?? []),
            is_array($records) ? $records : [],
        )));
    }
}
