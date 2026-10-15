<?php

namespace Omnilab\Simbad;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SIMBAD, the CDS's database of astronomical objects beyond the solar
 * system, through its TAP service (simbad.cds.unistra.fr/simbad/sim-tap),
 * in ADQL: an object by any of its identifiers ("M31", "Betelgeuse",
 * "HD 39801", "alf Ori" - SIMBAD resolves them); its main identifier, type,
 * ICRS coordinates, proper motion, parallax, radial velocity and redshift,
 * spectral and morphological types, magnitudes, and its other
 * designations; a search by identifier or by name.
 *
 * TAP rather than the script interface: one ADQL query joins the object,
 * its identifiers, its magnitudes and its type's description, and the
 * answer is JSON with typed columns, where a script answers text to parse.
 *
 * No key, no account. One call per entity, one per search page. SIMBAD
 * blacklists a client that sends more than about five to ten queries a
 * second: the calls are spaced by 0.2 s by default.
 */
final class SimbadSource extends HttpSource
{
    public const BASE_URI = 'https://simbad.cds.unistra.fr/simbad/sim-tap/';

    /** The most objects a search reads (ADQL 2.0 has no OFFSET: the pages are cut from them). */
    public const MAX_MATCHES = 200;

    /** SIMBAD's filters (allfluxes' columns) => the key's suffix. */
    private const FILTERS = [
        'U' => 'U', 'B' => 'B', 'V' => 'V', 'R' => 'R', 'I' => 'I', 'J' => 'J', 'H' => 'H', 'K' => 'K', 'G' => 'G',
        'u_' => 'sdss_u', 'g_' => 'sdss_g', 'r_' => 'sdss_r', 'i_' => 'sdss_i', 'z_' => 'sdss_z',
    ];

    private const COLUMNS = 'b.oid, b.main_id, b.otype, d.description AS otype_description, b.ra, b.dec, radec2sexa(b.ra, b.dec, 8) AS coordinates, '
        .'b.pmra, b.pmdec, b.plx_value, b.plx_err, b.rvz_radvel, b.rvz_err, b.rvz_redshift, b.sp_type, b.morph_type, b.galdim_majaxis, b.galdim_minaxis, b.nbref, s.ids, '
        .'f.U, f.B, f.V, f.R, f.I, f.J, f.H, f.K, f.G, f.u_, f.g_, f.r_, f.i_, f.z_';

    private const FROM = 'FROM basic AS b LEFT JOIN ids AS s ON s.oidref = b.oid LEFT JOIN allfluxes AS f ON f.oidref = b.oid LEFT JOIN otypedef AS d ON d.otype = b.otype';

    /**
     * @param int $synonyms how many other designations to keep (0: none)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $synonyms = 50,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.2,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'simbad';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /** An object by any identifier SIMBAD knows it under; the string is SIMBAD's to resolve. */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            if (Scheme::SIMBAD !== $id->scheme) {
                throw NotSupportedException::identifier('simbad', (string) $id);
            }
            $name = $id->value;
        } else {
            $parsed = Identifier::parse($id);
            if (null !== $parsed && Scheme::SIMBAD !== $parsed->scheme) {
                // Another base's identifier (a Wikidata item, a CAS number...): SIMBAD would not know it.
                throw NotSupportedException::identifier('simbad', $id);
            }
            $name = $parsed?->value ?? Identifier::tryOf(Scheme::SIMBAD, (string) preg_replace('~^simbad:\s*~i', '', $id))?->value;
            if (null === $name) {
                return null;
            }
        }
        $rows = $this->query('SELECT '.self::COLUMNS.' '.self::FROM.' WHERE b.oid IN (SELECT oidref FROM ident WHERE id = '.self::quote($name).')');

        return null === ($row = $rows[0] ?? null) ? null : $this->toEntity($row, $name);
    }

    /**
     * The objects an identifier names (SIMBAD resolves it, case and spaces
     * as they come) and those with a name that holds the text ("andromeda":
     * M 31, Andromeda I, II, III...): the one the identifier names first,
     * then the most cited. SIMBAD matches names in their own case: the
     * text is tried as written and with each word capitalised.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::ASTRONOMY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $like = str_replace('%', '', $text);
        $patterns = array_values(array_unique([$like, ucwords($like)]));
        $where = 'id = '.self::quote($text).implode('', array_map(static fn (string $p) => ' OR id LIKE '.self::quote('NAME %'.$p.'%'), $patterns));
        $rows = $this->query('SELECT TOP '.self::MAX_MATCHES.' '.self::COLUMNS.' '.self::FROM.' WHERE b.oid IN (SELECT oidref FROM ident WHERE '.$where.')');

        // The object the text identifies, then the most cited: SIMBAD's ORDER BY over the join can take minutes.
        $key = self::normalize($text);
        $named = static fn (array $row): bool => \in_array($key, array_map(self::normalize(...), [(string) $row['main_id'], ...explode('|', (string) ($row['ids'] ?? ''))]), true);
        usort($rows, static fn (array $a, array $b) => [$named($b), (int) $b['nbref']] <=> [$named($a), (int) $a['nbref']]);

        $offset = max(0, (int) $query->cursor);
        $limit = max(1, $query->limit);
        $items = array_map(fn (array $row) => $this->toEntity($row, $text), \array_slice($rows, $offset, $limit));
        $complete = \count($rows) < self::MAX_MATCHES;

        return new Page($items, $offset + $limit < \count($rows) ? (string) ($offset + $limit) : null, $complete ? \count($rows) : null);
    }

    /**
     * Runs an ADQL query on the TAP service, synchronously; the rows keyed by column.
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $adql): array
    {
        $data = $this->getJson('sync', ['request' => 'doQuery', 'lang' => 'adql', 'format' => 'json', 'query' => $adql]) ?? [];
        $columns = array_map(static fn (array $c) => (string) $c['name'], $data['metadata'] ?? []);
        $rows = [];
        foreach ($data['data'] ?? [] as $values) {
            if (\count($values) === \count($columns)) {
                $rows[] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param string|null          $asked the identifier or the text it was found by
     */
    private function toEntity(array $row, ?string $asked = null): Entity
    {
        $main = self::collapse((string) $row['main_id']);
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'simbad');
            }
        };
        $quantity = static fn (mixed $value, string $unit, mixed $error = null): ?Quantity => is_numeric($value)
            ? new Quantity(0 + $value, $unit, is_numeric($error) ? (float) $error : null)
            : null;
        $type = self::text($row['otype_description'] ?? null);
        $add('object_type', 'Object type', $type);
        $add('object_type_code', 'Object type (SIMBAD\'s code)', self::text($row['otype'] ?? null));
        $add('ra', 'Right ascension (ICRS, J2000)', $quantity($row['ra'] ?? null, 'deg'));
        $add('dec', 'Declination (ICRS, J2000)', $quantity($row['dec'] ?? null, 'deg'));
        $add('coordinates', 'Coordinates (ICRS, J2000, sexagesimal)', self::text($row['coordinates'] ?? null));
        $add('proper_motion_ra', 'Proper motion in right ascension (μα cos δ)', $quantity($row['pmra'] ?? null, 'mas/yr'));
        $add('proper_motion_dec', 'Proper motion in declination', $quantity($row['pmdec'] ?? null, 'mas/yr'));
        $add('parallax', 'Parallax', $quantity($row['plx_value'] ?? null, 'mas', $row['plx_err'] ?? null));
        $add('radial_velocity', 'Radial velocity', $quantity($row['rvz_radvel'] ?? null, 'km/s', $row['rvz_err'] ?? null));
        $add('redshift', 'Redshift', is_numeric($row['rvz_redshift'] ?? null) ? (float) $row['rvz_redshift'] : null);
        $add('spectral_type', 'Spectral type', self::text($row['sp_type'] ?? null));
        $add('morphological_type', 'Morphological type', self::text($row['morph_type'] ?? null));
        $add('angular_size_major', 'Angular size, major axis', $quantity($row['galdim_majaxis'] ?? null, 'arcmin'));
        $add('angular_size_minor', 'Angular size, minor axis', $quantity($row['galdim_minaxis'] ?? null, 'arcmin'));
        foreach (self::FILTERS as $column => $filter) {
            $add('magnitude_'.$filter, (str_starts_with($filter, 'sdss_') ? 'SDSS '.substr($filter, 5) : $filter).' magnitude', $quantity($row[$column] ?? null, 'mag'));
        }
        $add('references', 'References in SIMBAD', is_numeric($row['nbref'] ?? null) ? (int) $row['nbref'] : null);

        // The other designations, as SIMBAD writes them (spaces collapsed); a common name without its "NAME ".
        $names = [];
        $synonyms = [];
        foreach (explode('|', (string) ($row['ids'] ?? '')) as $designation) {
            $designation = self::collapse($designation);
            if (str_starts_with($designation, 'NAME ')) {
                $designation = substr($designation, 5);
                $names[] = $designation;
            }
            if ('' !== $designation && $designation !== $main) {
                $synonyms[$designation] = $designation;
            }
        }
        // The label: the main identifier when it is a name; else the name asked for; else the shortest name ("Sirius", not "Sirius A"); else the main identifier.
        usort($names, static fn (string $a, string $b) => [mb_strlen($a), $a] <=> [mb_strlen($b), $b]);
        $askedName = array_values(array_filter($names, static fn (string $n) => null !== $asked && self::normalize($n) === self::normalize($asked)));
        $label = str_starts_with($main, 'NAME ') ? substr($main, 5) : ($askedName[0] ?? $names[0] ?? $main);
        unset($synonyms[$label]);
        $synonyms = array_values($synonyms);
        if ($label !== $main && !str_starts_with($main, 'NAME ')) {
            array_unshift($synonyms, $main);
        }
        $id = Identifier::of(Scheme::SIMBAD, $main);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::ASTRONOMY,
            // SIMBAD's description of its type, as written: "Red Supergiant", "BY Dra Variable" (lower-casing would break the latter).
            kind: $type,
            synonyms: \array_slice($synonyms, 0, $this->synonyms),
            properties: $properties,
            url: $id->url(),
            source: 'simbad',
        );
    }

    /** An ADQL string literal. */
    private static function quote(string $text): string
    {
        return "'".str_replace("'", "''", $text)."'";
    }

    private static function collapse(string $designation): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $designation));
    }

    /** A designation as compared: spaces out, upper case, without "NAME ". */
    private static function normalize(string $designation): string
    {
        return (string) preg_replace('~\s+~u', '', mb_strtoupper((string) preg_replace('~^NAME\s+~i', '', trim($designation))));
    }
}
