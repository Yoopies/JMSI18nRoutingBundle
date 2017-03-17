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

use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use JMS\I18nRoutingBundle\Util\RouteExtractor;
use Symfony\Component\Config\Loader\LoaderResolver;

/**
 * This loader expands all routes which are eligible for i18n.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class I18nLoader
{
    const ROUTING_PREFIX = '__RG__';

    private $routeExclusionStrategy;
    private $patternGenerationStrategy;

    public function __construct(RouteExclusionStrategyInterface $routeExclusionStrategy, PatternGenerationStrategyInterface $patternGenerationStrategy)
    {
        $this->routeExclusionStrategy = $routeExclusionStrategy;
        $this->patternGenerationStrategy = $patternGenerationStrategy;
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
            if (count($patterns) > 1) {
                foreach ($patterns as $pattern => $locales) {
                    // If this pattern is used for more than one locale, we need to keep the original route to do url matching
                    if (count($locales) > 1) {
                        $catchMultipleRoute = clone $route;
                        $catchMultipleRoute->setPath($pattern);
                        $catchMultipleRoute->setDefault('_locales', $locales);
                        $i18nCollection->add(implode('_', $locales).I18nLoader::ROUTING_PREFIX.$name, $catchMultipleRoute);
                    }
                }
            }

            $patternsByLocale = $this->getPatternsByLocale($route, $patterns);
            $this->fillRouteCollection($i18nCollection, $name, $route, $patternsByLocale);
        }

        return $i18nCollection;
    }

    /**
     * We fill $patternsByLocale so that it each branch of this three has a default value which can be overriden by the children branches
     *
     * @param Route $route
     * @param array $patterns
     *
     * @return array
     */
    private function getPatternsByLocale(Route $route, array $patterns): array
    {
        $patternsByLocale = ['default' => $route->getPath(), 'locales' => []];
        foreach ($patterns as $pattern => $locales) {
            foreach ($locales as $locale) {
                $localeParts = preg_split('/[-_]/', $locale);

                $currentTable = &$patternsByLocale;
                for ($i = 0; $i < count($localeParts); $i++) {
                    if ($pattern != $currentTable['default']) {
                        if (!isset($currentTable[$localeParts[$i]])) {
                            $currentTable[$localeParts[$i]] = ['default' => $pattern, 'locales' => [$locale]];
                            break;
                        } else {
                            $currentTable = &$currentTable[$localeParts[$i]];
                        }
                    } else {
                        $currentTable['locales'][] = $locale;
                        break;
                    }
                }
            }
        }

        return $patternsByLocale;
    }

    /**
     * @param RouteCollection $i18nCollection
     * @param string          $name
     * @param Route           $route
     * @param array           $patternsByLocale
     * @param string          $localePrefix
     */
    private function fillRouteCollection(RouteCollection $i18nCollection, string $name, Route $route, array $patternsByLocale, string $localePrefix = null)
    {
        if (!empty($patternsByLocale['locales'])) {
            $localeRoute = clone $route;
            $localeRoute->setPath($patternsByLocale['default']);
            $localeRoute->setDefault('_locales', $patternsByLocale['locales']);
            $i18nCollection->add($localePrefix.I18nLoader::ROUTING_PREFIX.$name, $localeRoute);
        }

        foreach ($patternsByLocale as $localePart => $patterns) {
            if (!in_array($localePart, ['default', 'locales'])) {
                $this->fillRouteCollection($i18nCollection, $name, $route, $patterns, $localePrefix ? $localePrefix.'_'.$localePart : $localePart);
            }
        }
    }
}
