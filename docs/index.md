# omnilab/simbad

SIMBAD (CDS, Strasbourg), through its TAP service (`https://simbad.cds.unistra.fr/simbad/sim-tap/`),
for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): objects beyond the solar
system by any of their identifiers - type, coordinates, proper motion, parallax, radial velocity,
redshift, spectral type, magnitudes, designations - as entities; a search of names. No key.

## Installation

```sh
composer require omnilab/simbad
```

```php
use Omnilab\Simbad\SimbadSourceFactory;

$simbad = (new SimbadSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'synonyms' => 50,                  // optional
    'throttle' => 0.2,                 // optional: seconds between two queries
]);
```

With the Symfony bundle, the factory is registered as `simbad` when the bundle knows it;
otherwise register `SimbadSourceFactory` as a service (it is autoconfigured as a
`SourceFactoryInterface`).

## Credentials

None: SIMBAD is free and without registration. Give `mailto`, the address the CDS writes to before
it blocks a caller.

## Options

The factory's options, with the defaults it applies (`SimbadSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `synonyms` | `int` | `50` | no | how many other designations to keep; 0: none |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom the CDS writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.2` | no | seconds left between two queries: SIMBAD blacklists beyond about five to ten a second |
| `base_uri` | `string` | `https://simbad.cds.unistra.fr/simbad/sim-tap/` | no | SIMBAD's TAP service (`sync` is joined to it), or a mirror's |

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | any identifier SIMBAD knows (`Betelgeuse`, `M31`, `HD 39801`, `simbad:`, a `sim-id` page): one ADQL query; another base's identifier refused before any call |
| `search` | yes | the object the text identifies, then those whose names hold it, the most referenced first; the first 200 matches, paged by offset here, `total` known below 200; a domain other than astronomy answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none of another base: its other designations (HD, HIP, Gaia DR3...) are synonyms |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no | a position on the sky is not a place on the Earth: no cone search |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API: TAP, not the script interface

SIMBAD answers through its TAP service (`https://simbad.cds.unistra.fr/simbad/sim-tap/sync`,
ADQL 2.0, synchronous, `format=json`), its URL queries (`sim-id`, HTML or VOTable) and its script
interface (`sim-script`, text). TAP is used: one ADQL query joins the object (`basic`), every
identifier it has (`ids`, one `|`-separated string), its magnitudes (`allfluxes`, one column a
filter) and its type's description (`otypedef`), and the answer is JSON with typed columns and
units, where a script answers text to parse. SIMBAD's `ident.id = '...'` resolves an identifier
the way its pages do - case and spaces as they come, a common name without its `NAME ` - so
`M31`, `m31`, `M 31`, `Betelgeuse`, `alf Ori` and `HD 39801` all find their object.

## Calls

| Method | Reads | ADQL |
|---|---|---|
| `entity()` | any identifier SIMBAD knows (`Betelgeuse`, `M31`, `HD 39801`, `simbad:* alf Ori`, a `sim-id?Ident=` page), an `Identifier` of the `simbad` scheme | `SELECT ... FROM basic LEFT JOIN ids, allfluxes, otypedef WHERE b.oid IN (SELECT oidref FROM ident WHERE id = '...')` |
| `search()` | `Query::$text`: the object it identifies, and those whose names hold it; a domain other than astronomy answers nothing | the same, `TOP 200`, `WHERE id = '...' OR id LIKE 'NAME %text%' OR id LIKE 'NAME %Text%'` |

One query an entity, one a search page. A string that reads as another base's identifier (a
Wikidata item, an InChIKey, `jpl.sbdb:433`) is refused before any call; unknown is `null` (no
row). A quote in a name is doubled (`Barnard''s star`).

The search: ADQL 2.0 has no `OFFSET`, and SIMBAD's `ORDER BY` over this join took over 90 s for a
text whose matches are few (`HD 39801`, `sirius`) - so the source asks the first 200 matches
unordered (about a second), then puts first the object the text identifies and the others by
their number of references, and cuts the pages from them (`Page::$next` is an offset; `total` is
known below 200). Names match in their own case (`lowercase()` makes SIMBAD scan every
identifier: 17 s): the text is tried as written and with each word capitalised.

## What an entity holds

`id` (its main identifier, spaces collapsed: `simbad:M 31`, `simbad:* alf Ori`), `label` (the
main identifier when it is a name; else the name asked for; else its shortest name - Sirius, not
Sirius A; else the main identifier), `domain` astronomy, `kind` (SIMBAD's description of its type,
as written: `Red Supergiant`, `Active Galaxy Nucleus`, `BY Dra Variable`), `url` (its `sim-id`
page), `synonyms` (its other designations, the main identifier first when it is not the label, a
name without `NAME `, 50 by default), no description, no image, no `place` (a position on the sky
is not a place on the Earth: the core's `Point` is WGS 84), and these properties:

| Key | SIMBAD | Value |
|---|---|---|
| `object_type`, `object_type_code` | otypedef.description, basic.otype | text |
| `ra`, `dec` | ICRS, epoch J2000 | Quantity, deg |
| `coordinates` | `radec2sexa(ra, dec, 8)` | text: `05 55 10.3053 +07 24 25.430` |
| `proper_motion_ra`, `proper_motion_dec` | pmra (μα cos δ), pmdec | Quantity, mas/yr |
| `parallax` | plx_value ± plx_err | Quantity, mas |
| `radial_velocity` | rvz_radvel ± rvz_err | Quantity, km/s |
| `redshift` | rvz_redshift | number |
| `spectral_type`, `morphological_type` | sp_type, morph_type | text |
| `angular_size_major`, `angular_size_minor` | galdim_majaxis, galdim_minaxis | Quantity, arcmin |
| `magnitude_U`, `_B`, `_V`, `_R`, `_I`, `_J`, `_H`, `_K`, `_G`, `_sdss_u` ... `_sdss_z` | allfluxes: Johnson-Cousins, 2MASS, Gaia G, SDSS | Quantity, mag |
| `references` | nbref | number |

`identifiers`: its own, and its Gaia DR3 designation when SIMBAD gives one (`simbad:Gaia DR3
5853498713190525696` for Proxima Centauri): the same star in Gaia's catalogue, which omnilab/gaia
reads - the `Collector` follows a star from SIMBAD into Gaia by it. SIMBAD's other designations are
of catalogues the core has no scheme for (HD, HIP, Gaia DR2, 2MASS, TIC...): they are synonyms, and
the source declares no `CROSS_REFERENCES`.

## The Collector: Betelgeuse from Wikidata

Wikidata gives a star's SIMBAD identifier (P3083), which the Wikidata source reads into the
`simbad` scheme. Against the real services on 2026-10-15:

```php
$collector->collect('wikidata', 'Betelgeuse', 1, ['simbad']);
```

4 calls in 2.3 s - Wikidata's three (`wbsearchentities`, `wbgetentities` for Q12124, the labels
of its classes and units), then SIMBAD's query for `* alf Ori` - and `sources` = wikidata, simbad:

```
object_type       Red Supergiant [simbad]
parallax          6.55 ± 0.83 mas [simbad]
radial_velocity   21.91 ± 0.51 km/s [simbad]
magnitude_V       0.42 mag [simbad]
spectral_type     M1-M2Ia-Iab [simbad]
mass              17.8 ± 1.2 M☉ [wikidata]
radius            768 R☉ [wikidata]
```

## What SIMBAD does not give here

The measurement tables (`mesDistance`, `mesVar`, `mesRot`...), the bibliography, the
coordinates' and proper motion's error ellipses and quality letters, the hierarchy (what an
object belongs to), the cone search around a position, the criteria queries and the objects of
the solar system (SIMBAD has none: see omnilab/jpl) are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Simbad\SimbadSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$simbad = (new SimbadSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'synonyms' => 3]);

$betelgeuse = $simbad->entity('alf Ori');
echo $betelgeuse->label, ' (', $betelgeuse->id, '): ', $betelgeuse->value('object_type'), ', ', $betelgeuse->value('spectral_type'), ', parallax ', $betelgeuse->property('parallax'), ', V ', $betelgeuse->property('magnitude_V'), "\n";
echo '  also: ', implode(', ', $betelgeuse->synonyms), "\n";

$page = $simbad->search(new Query('andromeda', limit: 3));
printf("%d objects for \"andromeda\"; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($o) => $o->label, $page->items)));
```

```
Betelgeuse (simbad:* alf Ori): Red Supergiant, M1-M2Ia-Iab, parallax 6.55 ± 0.83 mas, V 0.42 mag
  also: * alf Ori, TIC 269273552, GEN# +1.00039801
42 objects for "andromeda"; the first 3: Andromeda, Andromeda II, Andromeda I
```

(as answered on 2026-10-27: two queries, 0.2 s apart)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        simbad: { factory: simbad, options: { mailto: '%env(OMNILAB_MAILTO)%', synonyms: 50 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

SIMBAD's own: an ADQL error is its 400 with a VOTable whose `QUERY_STATUS` says why, read into the
`ProviderException`'s message. An unknown name is no row, null. Before any call: a string that
reads as another base's identifier (a Wikidata item, an InChIKey, `jpl.sbdb:433`)
(`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15 (SIMBAD4 1.8, 22.2 million objects): Betelgeuse by its
name, by `HD 39801` and `alf Ori`; M31 by `M31`, `m31` and `M 31`; Proxima Centauri by `Proxima
Cen`; Sirius and Sirius B; Barnard's star (a quote) and Sgr A*; an unknown name (no row); the
searches for "andromeda" (42 objects), "sirius" (4) and an unknown text; an ADQL error (400 with
a VOTable saying why); the Collector's walk above. The fixtures in `Tests/Fixtures/` are its
answers.

Again on 2026-10-27: Betelgeuse by `alf Ori`, the search for "andromeda" (42 objects; the example
above).

Not verified: the blacklisting itself (no 429 met; the source stays at five queries a second);
a mirror's TAP service (`base_uri`); a search whose names match more than 200 objects, of which
only the first 200 SIMBAD returns are ranked.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the CDS (Université de Strasbourg / CNRS). SIMBAD is free, under the
[ODbL](https://opendatacommons.org/licenses/odbl/); cite the original papers and acknowledge SIMBAD
("This research has made use of the SIMBAD database, operated at CDS, Strasbourg, France", Wenger
et al. 2000), and keep under its rate, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-simbad#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-simbad#responsibility)
section.
