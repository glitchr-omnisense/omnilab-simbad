<?php

namespace Omnilab\Simbad\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Simbad\SimbadSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from simbad.cds.unistra.fr/simbad/sim-tap on
 * 2026-10-15 (SIMBAD4 1.8): the object query for "Betelgeuse", "M31",
 * "Proxima Cen" and an unknown name (no row), and the searches for
 * "andromeda" (42 objects), "sirius" (4) and an unknown text (none).
 */
final class SimbadSourceTest extends TestCase
{
    /** @var list<string> the ADQL queries asked */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('/simbad/sim-tap/sync', parse_url($url, \PHP_URL_PATH));
            self::assertSame(['doQuery', 'adql', 'json'], [$query['request'], $query['lang'], $query['format']]);
            $this->calls[] = $adql = $query['query'];
            if ($response) {
                return $response;
            }
            preg_match("~WHERE id = '((?:[^']|'')*)'~", $adql, $m);
            $search = str_contains($adql, 'LIKE');
            $fixture = match ($search ? 'search '.$m[1] : $m[1]) {
                'Betelgeuse', 'HD 39801' => 'object-betelgeuse.json',
                'M31', 'M 31' => 'object-m31.json',
                'Proxima Cen' => 'object-proxima.json',
                'search andromeda' => 'search-andromeda.json',
                'search sirius' => 'search-sirius.json',
                default => $search ? 'search-notfound.json' : 'object-notfound.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new SimbadSourceFactory($http))->create(['throttle' => 0] + $options);
    }

    public function testBetelgeuseByItsNameInOneQuery(): void
    {
        $star = $this->source()->entity('Betelgeuse');

        self::assertSame('simbad:* alf Ori', $star->id->key(), 'its main identifier, spaces collapsed');
        self::assertSame('Betelgeuse', $star->label, 'its common name, without "NAME "');
        self::assertSame(Domain::ASTRONOMY, $star->domain);
        self::assertSame('Red Supergiant', $star->kind);
        self::assertSame('s*r', $star->value('object_type_code'));
        self::assertEquals(new Quantity(88.79293899077537, 'deg'), $star->value('ra'));
        self::assertEquals(new Quantity(7.407063995272694, 'deg'), $star->value('dec'));
        self::assertSame('05 55 10.3053 +07 24 25.430', $star->value('coordinates'));
        self::assertEquals(new Quantity(27.54, 'mas/yr'), $star->value('proper_motion_ra'));
        self::assertEquals(new Quantity(11.3, 'mas/yr'), $star->value('proper_motion_dec'));
        self::assertSame('6.55 ± 0.83 mas', (string) $star->property('parallax'));
        self::assertSame('21.91 ± 0.51 km/s', (string) $star->property('radial_velocity'));
        self::assertSame('M1-M2Ia-Iab', $star->value('spectral_type'));
        self::assertSame('0.42 mag', (string) $star->property('magnitude_V'));
        self::assertSame('-4.05 mag', (string) $star->property('magnitude_K'));
        self::assertNull($star->property('magnitude_G'));
        self::assertSame(1804, $star->value('references'));
        self::assertSame('simbad', $star->property('parallax')->source);
        self::assertNull($star->place, 'a position on the sky is not a place on the Earth');
        self::assertSame('https://simbad.cds.unistra.fr/simbad/sim-id?Ident=%2A%20alf%20Ori', $star->url);
        self::assertCount(1, $this->calls);
        self::assertStringContainsString("FROM basic AS b LEFT JOIN ids AS s ON s.oidref = b.oid LEFT JOIN allfluxes AS f ON f.oidref = b.oid LEFT JOIN otypedef AS d ON d.otype = b.otype WHERE b.oid IN (SELECT oidref FROM ident WHERE id = 'Betelgeuse')", $this->calls[0]);
    }

    public function testItsOtherDesignations(): void
    {
        $star = $this->source()->entity('Betelgeuse');

        self::assertSame('* alf Ori', $star->synonyms[0], 'the main identifier first, when it is not the label');
        self::assertContains('HIP 27989', $star->synonyms);
        self::assertContains('HD 39801', $star->synonyms);
        self::assertContains('2MASS J05551028+0724255', $star->synonyms);
        self::assertNotContains('Betelgeuse', $star->synonyms);
        self::assertCount(45, $star->synonyms);
        self::assertCount(10, $this->source(null, ['synonyms' => 10])->entity('Betelgeuse')->synonyms);
        self::assertSame(['simbad:* alf Ori'], $star->identifiers()->keys(), 'no designation of a base the core knows');
    }

    public function testAGalaxyAndAStarNamedByName(): void
    {
        $galaxy = $this->source()->entity(Identifier::of(Scheme::SIMBAD, 'M31'));
        self::assertSame('simbad:M 31', $galaxy->id->key());
        self::assertSame('Andromeda', $galaxy->label, 'the shortest of its names');
        self::assertSame('Active Galaxy Nucleus', $galaxy->kind);
        self::assertSame('SA(s)b', $galaxy->value('morphological_type'));
        self::assertEquals(new Quantity(199.53, 'arcmin'), $galaxy->value('angular_size_major'));
        self::assertSame('-300 ± 4 km/s', (string) $galaxy->property('radial_velocity'));
        self::assertNull($galaxy->property('parallax'));

        $proxima = $this->source()->entity('Proxima Cen');
        self::assertSame('simbad:NAME Proxima Centauri', $proxima->id->key());
        self::assertSame('Proxima Centauri', $proxima->label, 'a main identifier that is a name');
        self::assertSame('768.0665 ± 0.0499 mas', (string) $proxima->property('parallax'));
        self::assertEquals(new Quantity(-3781.741, 'mas/yr'), $proxima->value('proper_motion_ra'));
        self::assertEquals(new Quantity(8.984749, 'mag'), $proxima->value('magnitude_G'));
    }

    public function testAnyIdentifierIsSimbadsToResolve(): void
    {
        $source = $this->source();
        foreach (['HD 39801', 'simbad:Betelgeuse', 'https://simbad.cds.unistra.fr/simbad/sim-id?Ident=Betelgeuse'] as $id) {
            self::assertSame('simbad:* alf Ori', $source->entity($id)?->id->key(), 'by '.$id);
        }
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('nonexistentobjectxyz'));
        self::assertNull($this->source()->entity(''));
    }

    public function testAnIdentifierOfAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::wikidata('Q12124'), 'Q12124', Identifier::of(Scheme::JPL_SBDB, '433'), 'RYYVLZVUVIJVGH-UHFFFAOYSA-N', 'jpl.sbdb:433'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testAQuoteIsEscaped(): void
    {
        $this->source()->entity("Barnard's star");
        self::assertStringContainsString("WHERE id = 'Barnard''s star')", $this->calls[0]);
    }

    public function testSearchTheIdentifiedFirstThenTheMostCited(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('sirius', limit: 2));

        self::assertSame(['simbad:* alf CMa', 'simbad:* alf CMa B'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['Sirius', 'Sirius B'], array_map(static fn ($e) => $e->label, $page->items), 'the name asked for, else the shortest');
        self::assertSame(4, $page->total);
        self::assertSame('2', $page->next);
        self::assertStringContainsString("SELECT TOP 200 ", $this->calls[0]);
        self::assertStringContainsString("WHERE id = 'sirius' OR id LIKE 'NAME %sirius%' OR id LIKE 'NAME %Sirius%')", $this->calls[0]);
        self::assertStringNotContainsString('ORDER BY', $this->calls[0], 'SIMBAD orders a join slowly: the source sorts');

        $second = $source->search(new Query('sirius', limit: 2, cursor: $page->next));
        self::assertSame(['Sirius Moving Group', 'Sirius-UMa Moving Group'], array_map(static fn ($e) => $e->label, $second->items));
        self::assertTrue($second->isLast());

        $andromeda = $source->search(new Query('andromeda', limit: 3));
        self::assertSame(['M 31', 'NAME Andromeda II', 'NAME Andromeda I'], array_map(static fn ($e) => $e->id->value, $andromeda->items));
        self::assertSame(42, $andromeda->total);

        self::assertSame([], $source->search(new Query('sirius', Domain::CHEMISTRY))->items, 'nothing of another domain');
        self::assertCount(3, $this->calls, 'and no call for it');
        $none = $source->search(new Query('nonexistentobjectxyz'));
        self::assertSame([0, true], [$none->total, $none->isLast()]);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('<VOTABLE><INFO name="QUERY_STATUS" value="ERROR">Incorrect ADQL query</INFO></VOTABLE>', ['http_code' => 400]))->entity('Betelgeuse');
            self::fail('a 400 is a ProviderException');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Incorrect ADQL query', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->entity('Betelgeuse');
            self::fail('a 503 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('Betelgeuse');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
