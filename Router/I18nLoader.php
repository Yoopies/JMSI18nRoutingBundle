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

namespace JMS\I18nRoutingBundle\Router;

use JMS\I18nRoutingBundle\Exception\RuntimeException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * This loader expands all routes which are eligible for i18n.
 *
 * Instead of adding one route per locale, locales sharing the same pattern are grouped into a tree
 * following the locale fallback chain ("fr_BE" then "fr"). A node is only materialized when its
 * pattern differs from the one it inherits from its parent, so a route which is not translated at
 * all ends up as a single route instead of one per locale.
 *
 * The routes are named the way Symfony names its own localized routes - "contact.fr_BE", "contact.fr"
 * and plain "contact" for the node every remaining locale falls back to - and carry the matching
 * "_canonical_route" default. The generator then resolves the right one by itself, walking the very
 * same chain the tree was built on.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class I18nLoader
{
    /**
     * @deprecated The localized routes are now named "<route>.<locale>", the way Symfony names its
     *             own, and no longer carry this prefix. Kept so that anything still reading the
     *             constant - FOSJsRoutingBundle's route extractor does - does not fatal, whichever
     *             order the packages happen to be updated in.
     */
    const ROUTING_PREFIX = '__RG__';

    /**
     * Marks a localized route that accepts every configured locale. Spelling it out would mean
     * dumping the whole locale list into both routing caches for every untranslated route.
     */
    const ALL_LOCALES = '_i18n_all_locales';

    /**
     * Separates a route name from the locales of a route that only exists for matching.
     * Deliberately not a valid locale, so that the generator never resolves it.
     */
    private const MATCH_ONLY_INFIX = '__i18n_';

    private $routeExclusionStrategy;
    private $patternGenerationStrategy;
    private $locales;

    public function __construct(RouteExclusionStrategyInterface $routeExclusionStrategy, PatternGenerationStrategyInterface $patternGenerationStrategy, array $locales = array())
    {
        $this->routeExclusionStrategy    = $routeExclusionStrategy;
        $this->patternGenerationStrategy = $patternGenerationStrategy;
        $this->locales                   = $locales;
    }

    public function load(RouteCollection $collection)
    {
        $i18nCollection = new RouteCollection();
        foreach ($collection->getResources() as $resource) {
            $i18nCollection->addResource($resource);
        }
        $this->patternGenerationStrategy->addResources($i18nCollection);

        foreach ($collection->all() as $name => $route) {
            if ($this->routeExclusionStrategy->shouldExcludeRoute($name, $route)) {
                $i18nCollection->add($name, $route);
                continue;
            }

            $patterns = $this->patternGenerationStrategy->generateI18nPatterns($name, $route);

            // A route restricted to a subset of locales cannot use the tree: its root node is the
            // fallback of every locale when generating, so it would hand out URLs for locales the
            // route is not available in. Those routes are rare, so keep one route per locale for them.
            if (null !== $route->getOption('i18n_locales')) {
                $this->addRoutePerLocale($i18nCollection, $name, $route, $patterns);
                continue;
            }

            $this->addI18nRoutes($i18nCollection, $name, $route, $patterns, $this->getPatternsByLocale($route, $patterns));
        }

        return $i18nCollection;
    }

    /**
     * The chain of route name suffixes a locale falls back on, shallowest first.
     *
     * Every part counts, and both separators delimit one: "fr_BE" falls back on "fr", and the
     * per-company locales - written "fr_FR-MYCOMPANY" - fall back on "fr_FR" then "fr". Symfony's own
     * generator is coarser, stripping only what follows the first underscore, so it would send
     * "fr_FR-MYCOMPANY" straight to "fr" and skip the country. I18nRouter::generate() walks those
     * locales itself for that reason; anything with at most two parts resolves identically either
     * way, and goes through Symfony untouched.
     *
     * @return array<int, string>
     */
    /**
     * The route name suffix a locale is registered under: its parts joined with underscores,
     * whichever separator the locale itself uses.
     */
    private function localeRouteSuffix(string $locale): string
    {
        return implode('_', preg_split('/[-_]/', $locale));
    }

    private function localeFallbackChain(string $locale): array
    {
        $parts = preg_split('/[-_]/', $locale);

        $chain = array();
        for ($i = 1, $count = count($parts); $i <= $count; $i++) {
            $chain[] = implode('_', array_slice($parts, 0, $i));
        }

        return $chain;
    }

    /**
     * Builds a tree of locales where each branch has a default pattern that its children may
     * override. A locale is only given a node of its own when its pattern differs from the one it
     * would otherwise inherit.
     */
    private function getPatternsByLocale(Route $route, array $patterns): array
    {
        $patternsByLocale = array('default' => $route->getPath(), 'locales' => array());

        // A node is named after the part of the locale chain it covers, and generating for a locale
        // looks that name up. Naming a node after another locale of this route would therefore hand
        // that locale the wrong path: "fr_FR-MYCOMPANY" must not take the "fr_FR" name, which belongs
        // to "fr_FR" itself.
        $claimed = array();
        foreach ($patterns as $patternLocales) {
            foreach ($patternLocales as $patternLocale) {
                $claimed[$this->localeRouteSuffix($patternLocale)] = true;
            }
        }

        foreach ($this->sortLocalePatterns($patterns) as list($locale, $pattern)) {
            $ownSuffix    = $this->localeRouteSuffix($locale);
            $registered   = false;
            $currentTable = &$patternsByLocale;
            foreach ($this->localeFallbackChain($locale) as $suffix) {
                if ($pattern === $currentTable['default']) {
                    $currentTable['locales'][] = $locale;
                    $registered = true;
                    break;
                }

                if (isset($currentTable[$suffix])) {
                    $currentTable = &$currentTable[$suffix];
                    continue;
                }

                // Taken by another locale: go one level deeper rather than steal its name.
                if ($suffix !== $ownSuffix && isset($claimed[$suffix])) {
                    continue;
                }

                $currentTable[$suffix] = array('default' => $pattern, 'locales' => array($locale));
                $registered = true;
                break;
            }
            unset($currentTable);

            // Unreachable as long as the locales are sorted by depth: a locale always reaches either a
            // node holding its own pattern or a free slot. Fail loudly rather than silently dropping
            // the locale, which would leave it inheriting another locale's path.
            if (!$registered) {
                throw new RuntimeException(sprintf('Could not place locale "%s" for pattern "%s" in the locale tree.', $locale, $pattern));
            }
        }

        return $patternsByLocale;
    }

    /**
     * Flattens the patterns into (locale, pattern) pairs, shallowest locale first so that a parent
     * locale always owns its node, then most shared pattern first so that the node holds the pattern
     * used by the majority of the locales falling back on it.
     *
     * Without this ordering the node is claimed by whichever locale happens to come first in the
     * configuration, which both costs routes and makes the result depend on the locale order.
     */
    private function sortLocalePatterns(array $patterns): array
    {
        $localePatterns = array();
        $index          = 0;
        foreach ($patterns as $pattern => $locales) {
            foreach ($locales as $locale) {
                $localePatterns[] = array($locale, (string) $pattern, count($this->localeFallbackChain($locale)), count($locales), $index++);
            }
        }

        usort($localePatterns, static function (array $a, array $b) {
            return ($a[2] <=> $b[2]) ?: (($b[3] <=> $a[3]) ?: ($a[4] <=> $b[4]));
        });

        return $localePatterns;
    }

    /**
     * Turns the tree back into routes, pattern by pattern.
     *
     * The patterns are walked in the order the strategy produced them rather than in tree order:
     * when two patterns can match the same URL - which happens as soon as a translated pattern is
     * made of variables only, such as "/{type}/{city}" - the winner is decided by insertion order,
     * and reordering them here would silently change which one answers.
     */
    private function addI18nRoutes(RouteCollection $i18nCollection, string $name, Route $route, array $patterns, array $patternsByLocale): void
    {
        $nodesByPattern = array();
        foreach ($this->flattenNodes($patternsByLocale) as $node) {
            $nodesByPattern[$node['pattern']][] = $node;
        }

        foreach ($patterns as $pattern => $locales) {
            $nodes = $nodesByPattern[$pattern] ?? array();

            // A pattern spread over several nodes needs an extra route listing all of its locales,
            // registered first. Matching would otherwise stop on the first node and reject every
            // locale held by the others. A pattern held by a single node needs no such route: that
            // node already accepts all of its locales.
            if (count($nodes) > 1 && count($locales) > 1) {
                $catchMultipleRoute = clone $route;
                $catchMultipleRoute->setPath($pattern);
                $catchMultipleRoute->setDefault('_canonical_route', $name);
                $catchMultipleRoute->setDefault('_locale', reset($locales));
                $catchMultipleRoute->setDefault('_locales', $locales);
                $i18nCollection->add($name.'.'.self::MATCH_ONLY_INFIX.implode('_', $locales), $catchMultipleRoute);
            }

            foreach ($nodes as $node) {
                $localeRoute = clone $route;
                $localeRoute->setPath($pattern);
                $localeRoute->setDefault('_canonical_route', $name);
                $this->setLocaleDefaults($localeRoute, $node['locales']);
                $i18nCollection->add('' === $node['suffix'] ? $name : $name.'.'.$node['suffix'], $localeRoute);
            }
        }
    }

    /**
     * @return array<int, array{suffix: string, pattern: string, locales: array}> the nodes holding locales
     */
    private function flattenNodes(array $patternsByLocale, string $suffix = ''): array
    {
        $nodes = array();
        if (!empty($patternsByLocale['locales'])) {
            $nodes[] = array(
                'suffix'  => $suffix,
                'pattern' => $patternsByLocale['default'],
                'locales' => $patternsByLocale['locales'],
            );
        }

        foreach ($patternsByLocale as $childSuffix => $child) {
            if ('default' !== $childSuffix && 'locales' !== $childSuffix) {
                $nodes = array_merge($nodes, $this->flattenNodes($child, $childSuffix));
            }
        }

        return $nodes;
    }

    /**
     * Restores the upstream behaviour of registering one route per locale. Used for routes whose
     * locales are restricted, where the tree's fallback node would be too permissive.
     */
    private function addRoutePerLocale(RouteCollection $i18nCollection, string $name, Route $route, array $patterns): void
    {
        foreach ($patterns as $pattern => $locales) {
            // If this pattern is used for more than one locale, we need a route matching them all.
            // We still add individual routes for each locale afterwards for faster generation.
            if (count($locales) > 1) {
                $catchMultipleRoute = clone $route;
                $catchMultipleRoute->setPath($pattern);
                $catchMultipleRoute->setDefault('_canonical_route', $name);
                $catchMultipleRoute->setDefault('_locale', reset($locales));
                $catchMultipleRoute->setDefault('_locales', $locales);
                $i18nCollection->add($name.'.'.self::MATCH_ONLY_INFIX.implode('_', $locales), $catchMultipleRoute);
            }

            foreach ($locales as $locale) {
                $localeRoute = clone $route;
                $localeRoute->setPath($pattern);
                $localeRoute->setDefault('_canonical_route', $name);
                $localeRoute->setDefault('_locale', $locale);
                $i18nCollection->add($name.'.'.$locale, $localeRoute);
            }
        }
    }

    private function setLocaleDefaults(Route $route, array $locales): void
    {
        // Always set, and always to a real locale: the generator relies on the "_canonical_route" and
        // "_locale" pair to drop the "_locale" parameter instead of appending it to the query string.
        $route->setDefault('_locale', reset($locales));

        if (1 === count($locales)) {
            return;
        }

        // When the node accepts every configured locale, a flag is enough. Spelling the list out is
        // what the bulk of the untranslated routes would otherwise pay for, in both routing caches.
        //
        // I18nRouter::matchI18n() reads the list back from the "jms_i18n_routing.locales" parameter
        // when it sees the flag. Both sides must stay in step: the flag is only set when the loader
        // was given the locales, and the router only trusts it when the parameter is there.
        if (array() !== $this->locales && array() === array_diff($this->locales, $locales)) {
            $route->setDefault(self::ALL_LOCALES, true);

            return;
        }

        $route->setDefault('_locales', $locales);
    }
}
