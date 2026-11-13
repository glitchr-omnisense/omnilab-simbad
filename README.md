# omnilab/simbad

SIMBAD for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): an astronomical object
beyond the solar system by any of its identifiers - `M31`, `Betelgeuse`, `HD 39801`, `alf Ori`,
SIMBAD resolves them - with its main identifier, its type, its ICRS coordinates, proper motion,
parallax, radial velocity and redshift, its spectral or morphological type, its magnitudes and
its other designations; a search by identifier or by name. Through SIMBAD's TAP service, in ADQL,
no key.

```php
$simbad = (new SimbadSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$star = $simbad->entity('Betelgeuse');
$star->id;                               // simbad:* alf Ori
$star->kind;                             // Red Supergiant
$star->property('parallax');             // 6.55 ± 0.83 mas
$star->property('radial_velocity');      // 21.91 ± 0.51 km/s
$star->value('coordinates');             // 05 55 10.3053 +07 24 25.430
$star->property('magnitude_V');          // 0.42 mag
$star->synonyms;                         // * alf Ori, TIC 269273552, HIP 27989, HD 39801... (45)
$simbad->search(new Query('andromeda', limit: 5));   // M 31, Andromeda II, Andromeda I...
```

```yaml
omnilab:
    sources:
        simbad:
            factory: simbad
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                synonyms: 50                   # how many other designations to keep; 0: none
                throttle: 0.2                  # seconds between two queries
```

With Wikidata configured too, the `Collector` follows a star from one to the other: Wikidata's
SIMBAD identifier (P3083) leads to SIMBAD, `$collector->collect('wikidata', 'Betelgeuse')`.

## Install and a first call

```sh
composer require omnilab/simbad
```

A real call, with no account and no key: Betelgeuse in SIMBAD. `mailto` is your address, sent in the
User-Agent so that the service can reach you before it blocks a caller; the source keeps to the
limits its [`policy.json`](policy.json) declares.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Simbad\SimbadSourceFactory;

$simbad = (new SimbadSourceFactory())->create(['mailto' => 'you@example.org']);   // your address, in the User-Agent
$star = $simbad->entity('Betelgeuse');
echo $star->id, ' (', $star->kind, '), parallax ', $star->property('parallax'), "\n";
```

Not run on 2026-11-13: the CDS's services (SIMBAD, VizieR) answered their maintenance page (404) that day.

## Terms of use

SIMBAD is run by the CDS (Strasbourg astronomical data centre), free and without registration,
under the [ODbL](https://opendatacommons.org/licenses/odbl/); cite the original papers, and
acknowledge SIMBAD ("This research has made use of the SIMBAD database, operated at CDS,
Strasbourg, France", Wenger et al. 2000, A&AS 143, 9). SIMBAD blacklists for up to an hour a
client that sends more than about five to ten queries a second (as astroquery's documentation
warns; the CDS's own pages give no figure): the source spaces its queries by
0.2 s and asks one per entity and one per search page; cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what SIMBAD does not give here.

## Usage policy

Declared as data in [`policy.json`](policy.json) (its pages could not be read on 2026-11-10 (https://cds.unistra.fr/help/faq/simbad/): see the notes in the file), and honoured on every request by the
core: a limit reached waits when its window ends within `max_wait` seconds, else the request is not
sent ([Usage policies](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/policies.md)).

- Limits: 10 a second per client-ip; 400 every 10 seconds per client-ip.
- Licence: ODbL-1.0; commercial use: allowed.
- Credit: required: This research has made use of the SIMBAD database, CDS, Strasbourg Astronomical Observatory, France (https://simbad.cds.unistra.fr/simbad/).
- Needs: nothing.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the CDS, Strasbourg (SIMBAD). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
