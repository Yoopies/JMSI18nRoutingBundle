<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\I18nRoutingBundle\Tests\Router;

use JMS\I18nRoutingBundle\Router\DefaultPatternGenerationStrategy;

use JMS\I18nRoutingBundle\Router\DefaultRouteExclusionStrategy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use JMS\I18nRoutingBundle\Router\I18nLoader;

class I18nLoaderTest extends TestCase
{
    public function testLoad()
    {
        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $this->getLoader()->load($col);

        self::assertEquals(2, count($i18nCol->all()));

        $de = $i18nCol->get('contact.de');
        self::assertEquals('/kontakt', $de->getPath());
        self::assertEquals('de', $de->getDefault('_locale'));

        // "en" keeps the original path, so it stays on the root node of the locale tree, which takes
        // the plain route name: the locale every other one falls back on when generating.
        $en = $i18nCol->get('contact');
        self::assertEquals('/contact', $en->getPath());
        self::assertEquals('en', $en->getDefault('_locale'));
    }

    public function testLoadKeepsASingleRouteWhenNoLocaleOverridesThePattern()
    {
        $col = new RouteCollection();
        $col->add('support', new Route('/support'));
        $i18nCol = $this->getLoader('custom')->load($col);

        // Every locale shares the original path, so a single route carries them all.
        self::assertEquals(array('support'), array_keys($i18nCol->all()));

        $route = $i18nCol->get('support');
        self::assertEquals('/support', $route->getPath());
        self::assertEquals(array('en', 'de'), $route->getDefault('_locales'));
    }

    /**
     * @dataProvider getStrategies
     */
    public function testLoadDoesNotAddI18nRoutesIfI18nIsFalse($strategy)
    {
        $col = new RouteCollection();
        $col->add('route', new Route('/no-i18n', array(), array(), array('i18n' => false)));
        $i18nCol = $this->getLoader($strategy)->load($col);

        self::assertEquals(1, count($i18nCol->all()));
        self::assertNull($i18nCol->get('route')->getDefault('_locale'));
    }

    public function testLoadUsesOriginalTranslationIfNoTranslationExists()
    {
        $col = new RouteCollection();
        $col->add('untranslated_route', new Route('/not-translated'));
        $i18nCol = $this->getLoader()->load($col);

        self::assertEquals(1, count($i18nCol->all()));
        self::assertEquals('/not-translated', $i18nCol->get('untranslated_route')->getPath());
        self::assertEquals(array('en', 'de'), $i18nCol->get('untranslated_route')->getDefault('_locales'));
    }

    public function testLoadIfRouteIsNotTranslatedToAllLocales()
    {
        $col = new RouteCollection();
        $col->add('route', new Route('/not-available-everywhere', array(), array(), array('i18n_locales' => array('en'))));
        $i18nCol = $this->getLoader()->load($col);

        self::assertEquals(array('route.en'), array_keys($i18nCol->all()));
    }

    public function testLoadIfStrategyIsPrefix()
    {
        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $this->getLoader('prefix')->load($col);

        self::assertEquals(2, count($i18nCol->all()));

        $de = $i18nCol->get('contact.de');
        self::assertEquals('/de/kontakt', $de->getPath());

        $en = $i18nCol->get('contact.en');
        self::assertEquals('/en/contact', $en->getPath());
    }

    public function testLoadIfStrategyIsPrefixExceptDefault()
    {
        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $this->getLoader('prefix_except_default')->load($col);

        self::assertEquals(2, count($i18nCol->all()));

        $de = $i18nCol->get('contact.de');
        self::assertEquals('/de/kontakt', $de->getPath());

        // The default locale is not prefixed, so it keeps the original path and stays on the root node.
        $en = $i18nCol->get('contact');
        self::assertEquals('/contact', $en->getPath());
    }

    public function testLoadAddsPrefix()
    {
        $col = new RouteCollection();
        $col->add('dashboard', new Route('/dashboard', array(), array(), array('i18n_prefix' => '/admin')));
        $i18nCol = $this->getLoader('prefix')->load($col);

        $en = $i18nCol->get('dashboard.en');
        self::assertEquals('/admin/en/dashboard', $en->getPath());
    }

    /**
     * Reproduces the production shape: many "xx_YY" locales spread over a few languages, with a
     * sub-locale (here "en_AS") listed before the other locales of its language. The language node
     * must be owned by the pattern shared by the majority, not by whichever locale comes first.
     */
    public function testLoadGivesTheLanguageNodeToTheMostSharedPattern()
    {
        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $this->getSubLocaleLoader()->load($col);

        // The routes follow the pattern order produced by the strategy, which follows the locale order.
        self::assertEquals(array(
            'contact.en_AS',
            'contact.en',
            'contact.de_AT',
            'contact.de',
            'contact.fr',
        ), array_keys($i18nCol->all()));

        self::assertEquals('/contact-en', $i18nCol->get('contact.en')->getPath());
        self::assertEquals(array('en_GB', 'en_FR'), $i18nCol->get('contact.en')->getDefault('_locales'));

        self::assertEquals('/contact-as', $i18nCol->get('contact.en_AS')->getPath());
        self::assertEquals('en_AS', $i18nCol->get('contact.en_AS')->getDefault('_locale'));

        self::assertEquals('/kontakt', $i18nCol->get('contact.de')->getPath());
        self::assertEquals('/kontakt-at', $i18nCol->get('contact.de_AT')->getPath());
        self::assertEquals('/contact-fr', $i18nCol->get('contact.fr')->getPath());
    }

    /**
     * A pattern carried by a single node needs no extra route: that node already accepts all of its
     * locales.
     */
    public function testLoadDoesNotAddARouteForAPatternHeldByASingleNode()
    {
        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $this->getSubLocaleLoader()->load($col);

        foreach (array_keys($i18nCol->all()) as $name) {
            self::assertStringNotContainsString('en_GB_en_FR', $name);
        }
    }

    /**
     * When several languages end up sharing the very same pattern, that pattern is split over several
     * nodes. Matching would then stop on the first node and reject the other locales, so an extra
     * route listing every locale of the pattern is required.
     */
    public function testLoadAddsARouteForAPatternSharedBySeveralNodes()
    {
        $col = new RouteCollection();
        $col->add('shared', new Route('/shared'));
        $i18nCol = $this->getSubLocaleLoader()->load($col);

        // "/gemeinsam" is held by both the "de" and the "fr" nodes.
        $shared = $i18nCol->get('shared.__i18n_de_AT_de_DE_de_CH_fr_FR_fr_BE');
        self::assertNotNull($shared);
        self::assertEquals('/gemeinsam', $shared->getPath());
        self::assertEquals(array('de_AT', 'de_DE', 'de_CH', 'fr_FR', 'fr_BE'), $shared->getDefault('_locales'));

        // It has to come before the nodes carrying that pattern, which are matched in insertion order.
        $names = array_keys($i18nCol->all());
        self::assertLessThan(array_search('shared.de', $names), array_search('shared.__i18n_de_AT_de_DE_de_CH_fr_FR_fr_BE', $names));
        self::assertLessThan(array_search('shared.fr', $names), array_search('shared.__i18n_de_AT_de_DE_de_CH_fr_FR_fr_BE', $names));
    }

    /**
     * A route restricted to a subset of locales cannot use the tree: its root node is reachable from
     * any locale when generating, which would produce URLs for locales the route is not available in.
     */
    public function testLoadKeepsOneRoutePerLocaleForRestrictedRoutes()
    {
        $col = new RouteCollection();
        $col->add('route', new Route('/restricted', array(), array(), array('i18n_locales' => array('en', 'de'))));
        $i18nCol = $this->getLoader()->load($col);

        self::assertEquals(array(
            'route.__i18n_en_de',
            'route.en',
            'route.de',
        ), array_keys($i18nCol->all()));
        self::assertEquals('en', $i18nCol->get('route.en')->getDefault('_locale'));
        self::assertEquals('de', $i18nCol->get('route.de')->getDefault('_locale'));
    }

    /**
     * The locale list is the bulk of what untranslated routes contribute to the routing caches, and
     * it carries no information when it covers every configured locale.
     */
    public function testLoadOmitsTheLocaleListWhenItCoversEveryConfiguredLocale()
    {
        $col = new RouteCollection();
        $col->add('support', new Route('/support'));
        $i18nCol = $this->getLoader('custom', array('en', 'de'))->load($col);

        $route = $i18nCol->get('support');
        self::assertNull($route->getDefault('_locales'));
        self::assertTrue($route->getDefault(I18nLoader::ALL_LOCALES));

        // The generator needs the "_canonical_route" and "_locale" pair to drop the "_locale"
        // parameter instead of appending it to the query string.
        self::assertEquals('support', $route->getDefault('_canonical_route'));
        self::assertEquals('en', $route->getDefault('_locale'));
    }

    /**
     * The per-company locales are written "fr_FR-MYCOMPANY": three parts, and a hyphen. Each part is a
     * level of its own, so such a locale falls back on its country before falling back on its
     * language.
     */
    public function testLoadGivesPerCompanyLocalesTheirOwnLevel()
    {
        $translator = new Translator('fr_FR');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', array('contact' => '/contact-fr'), 'fr', 'routes');
        $translator->addResource('array', array('contact' => '/contact-be'), 'fr_BE', 'routes');
        $translator->addResource('array', array('contact' => '/contact-altarea'), 'fr_FR-MYCOMPANY', 'routes');

        $locales = array('fr_FR', 'fr_BE', 'fr_FR-MYCOMPANY', 'fr_BE-ACME');
        $loader  = new I18nLoader(
            new DefaultRouteExclusionStrategy(),
            new DefaultPatternGenerationStrategy('custom', $translator, $locales, sys_get_temp_dir()),
            $locales
        );

        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $loader->load($col);

        // The overriding company locale gets a node of its own, named with underscores whatever the
        // separator in the locale - both the router and the JavaScript client rely on that shape.
        $altarea = $i18nCol->get('contact.fr_FR_ALTAREA');
        self::assertNotNull($altarea);
        self::assertEquals('/contact-altarea', $altarea->getPath());
        self::assertEquals('fr_FR-MYCOMPANY', $altarea->getDefault('_locale'));

        // The company locale without a catalogue of its own claims no node: it rides on the one
        // holding its country's pattern.
        self::assertNull($i18nCol->get('contact.fr_BE_ACME'));
        $paths = array();
        foreach ($i18nCol->all() as $route) {
            $paths[$route->getPath()] = array_merge(
                $route->getDefault('_locales') ?? array(),
                array_filter(array($route->getDefault('_locale')))
            );
        }
        self::assertContains('fr_BE-ACME', $paths['/contact-be']);
    }

    /**
     * A node is named after the part of the locale chain it covers, and generating for a locale looks
     * that name up. When "fr_FR" shares its country's pattern it holds no node of its own, and a
     * company locale diverging below it must not take the free "fr_FR" name: "fr_FR" would then
     * resolve to the company's path.
     */
    public function testLoadDoesNotNameACompanyNodeAfterAnotherLocale()
    {
        $translator = new Translator('fr_FR');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', array('contact' => '/contact-fr'), 'fr', 'routes');
        $translator->addResource('array', array('contact' => '/contact-altarea'), 'fr_FR-MYCOMPANY', 'routes');

        $locales = array('fr_FR', 'fr_BE', 'fr_FR-MYCOMPANY');
        $loader  = new I18nLoader(
            new DefaultRouteExclusionStrategy(),
            new DefaultPatternGenerationStrategy('custom', $translator, $locales, sys_get_temp_dir()),
            $locales
        );

        $col = new RouteCollection();
        $col->add('contact', new Route('/contact'));
        $i18nCol = $loader->load($col);

        // "fr_FR" and "fr_BE" share the language node, so no route is named after either of them.
        self::assertNull($i18nCol->get('contact.fr_FR'));
        self::assertEquals('/contact-fr', $i18nCol->get('contact.fr')->getPath());
        self::assertEquals(array('fr_FR', 'fr_BE'), $i18nCol->get('contact.fr')->getDefault('_locales'));

        self::assertEquals('/contact-altarea', $i18nCol->get('contact.fr_FR_ALTAREA')->getPath());
    }

    public function getStrategies()
    {
        return array(array('custom'), array('prefix'), array('prefix_except_default'));
    }

    private function getSubLocaleLoader(): I18nLoader
    {
        $translator = new Translator('en_GB');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', array('contact' => '/contact-en'), 'en', 'routes');
        $translator->addResource('array', array('contact' => '/contact-as'), 'en_AS', 'routes');
        $translator->addResource('array', array('contact' => '/kontakt', 'shared' => '/gemeinsam'), 'de', 'routes');
        $translator->addResource('array', array('contact' => '/kontakt-at'), 'de_AT', 'routes');
        $translator->addResource('array', array('contact' => '/contact-fr', 'shared' => '/gemeinsam'), 'fr', 'routes');

        // "en_AS" first, like the production configuration where the Asian locale precedes the other
        // English ones.
        $locales = array('en_AS', 'en_GB', 'en_FR', 'de_AT', 'de_DE', 'de_CH', 'fr_FR', 'fr_BE');

        return new I18nLoader(
            new DefaultRouteExclusionStrategy(),
            new DefaultPatternGenerationStrategy('custom', $translator, $locales, sys_get_temp_dir()),
            $locales
        );
    }

    private function getLoader($strategy = 'custom', array $locales = array())
    {
        $translator = new Translator('en');
        $translator->addLoader('yml', new YamlFileLoader());
        $translator->addResource('yml', __DIR__.'/Fixture/routes.de.yml', 'de', 'routes');
        $translator->addResource('yml', __DIR__.'/Fixture/routes.en.yml', 'en', 'routes');

        return new I18nLoader(
            new DefaultRouteExclusionStrategy(),
            new DefaultPatternGenerationStrategy($strategy, $translator, array('en', 'de'), sys_get_temp_dir()),
            $locales
        );
    }
}
