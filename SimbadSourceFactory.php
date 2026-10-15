<?php

namespace Omnilab\Simbad;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * SIMBAD: astronomical objects beyond the solar system by any of their
 * identifiers, their type, position, motion, magnitudes and designations -
 * no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom the CDS writes to before it blocks a caller
 *     synonyms: 50                     # how many other designations to keep (0: none)
 *     throttle: 0.2                    # seconds between two calls: SIMBAD blacklists beyond about 5 to 10 queries a second
 *     base_uri: 'https://simbad.cds.unistra.fr/simbad/sim-tap/'   # or a mirror's TAP service
 */
final class SimbadSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'simbad',
            'omnilab.required_options' => [],
            'synonyms' => 50,
            'throttle' => 0.2,
            'base_uri' => SimbadSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new SimbadSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('synonyms'),
            (string) $c->get('base_uri', SimbadSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
