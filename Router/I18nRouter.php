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

use JMS\I18nRoutingBundle\Exception\NotAcceptableLanguageException;

use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

/**
 * I18n Router implementation.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class I18nRouter extends Router
{
    private $hostMap = array();
    private $i18nLoaderId;
    private $container;
    private $redirectToHost = true;
    private $localeResolver;

    /**
     * Constructor.
     *
     * The only purpose of this is to make the container available in the sub-class
     * since it is declared private in the parent class.
     *
     * The parameters are not listed explicitly here because they are different for
     * Symfony 2.0 and 2.1. If we did list them, it would make this class incompatible
     * with one of both versions.
     */
    public function __construct()
    {
        call_user_func_array(array('Symfony\Bundle\FrameworkBundle\Routing\Router', '__construct'), func_get_args());
        $this->container = func_get_arg(0);
    }

    public function setLocaleResolver(LocaleResolverInterface $resolver)
    {
        $this->localeResolver = $resolver;
    }

    /**
     * Whether the user should be redirected to a different host if the
     * matching route is not belonging to the current domain.
     *
     * @param Boolean $bool
     */
    public function setRedirectToHost($bool)
    {
        $this->redirectToHost = (Boolean) $bool;
    }

    /**
     * Sets the host map to use.
     *
     * @param array $hostMap a map of locales to hosts
     */
    public function setHostMap(array $hostMap)
    {
        $this->hostMap = $hostMap;
    }

    public function setI18nLoaderId($id)
    {
        $this->i18nLoaderId = $id;
    }

    public function setDefaultLocale($locale)
    {
        $this->defaultLocale = $locale;
    }

    /**
     * {@inheritdoc}
     */
    public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH): string
    {
        // determine the most suitable locale to use for route generation
        $currentLocale = $this->context->getParameter('_locale');
        if (isset($parameters['_locale'])) {
            $locale = $parameters['_locale'];
        } else if ($currentLocale) {
            $locale = $currentLocale;
        } else {
            $locale = $this->defaultLocale;
        }

        // if the locale is changed, and we have a host map, then we need to
        // generate an absolute URL
        if ($currentLocale && $currentLocale !== $locale && $this->hostMap) {
            $referenceType = self::NETWORK_PATH === $referenceType ? self::NETWORK_PATH : self::ABSOLUTE_URL;
        }
        $needsHost = self::NETWORK_PATH === $referenceType || self::ABSOLUTE_URL === $referenceType;

        $generator = $this->getGenerator();

        // if an absolute or network URL is requested, we set the correct host
        if ($needsHost && $this->hostMap) {
            $currentHost = $this->context->getHost();
            $this->context->setHost($this->hostMap[$locale]);
        }

        // The loader names the localized routes the way Symfony names its own ("contact.fr_BE",
        // "contact.fr", plain "contact"), so the generator resolves the right one on its own by
        // walking the locale down, and drops the "_locale" parameter rather than appending it to the
        // query string. Publishing the locale we settled on through the context is all it takes, and
        // it leaves the caller's parameters untouched - a route excluded from i18n still receives
        // them verbatim.
        $currentContextLocale = $this->context->getParameter('_locale');
        $this->context->setParameter('_locale', $locale);

        try {
            // Symfony only strips what follows the first underscore, so it would send a per-company
            // locale such as "fr_FR-ALTAREA" straight to "<route>.fr" and skip "<route>.fr_FR".
            // Those are walked here; the last step, "<route>.fr" and then the plain route name, is
            // what Symfony resolves below anyway.
            // A locale of at most two parts resolves identically either way, so it costs nothing and
            // goes through Symfony untouched.
            $localeParts = preg_split('/[-_]/', (string) $locale);
            if (count($localeParts) > 2) {
                for ($i = count($localeParts); $i > 1; $i--) {
                    try {
                        return $generator->generate($name.'.'.implode('_', array_slice($localeParts, 0, $i)), $parameters, $referenceType);
                    } catch (RouteNotFoundException $ex) {
                    }
                }
            }

            return $generator->generate($name, $parameters, $referenceType);
        } finally {
            $this->context->setParameter('_locale', $currentContextLocale);

            if ($needsHost && $this->hostMap) {
                $this->context->setHost($currentHost);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function match(string $pathinfo): array
    {
        return $this->matchI18n(parent::match($pathinfo), $pathinfo);
    }

    public function getRouteCollection(): RouteCollection
    {
        $collection = parent::getRouteCollection();

        return $this->container->get($this->i18nLoaderId)->load($collection);
    }

    public function getOriginalRouteCollection()
    {
        return parent::getRouteCollection();
    }

    /**
     * To make compatible with Symfony <2.4
     *
     * @inheritDoc
     */
    public function matchRequest(Request $request): array
    {
        $matcher = $this->getMatcher();
        $pathInfo = $request->getPathInfo();
        if (!$matcher instanceof RequestMatcherInterface) {
            // fallback to the default UrlMatcherInterface
            return $this->matchI18n($matcher->match($pathInfo), $pathInfo);
        }

        return $this->matchI18n($matcher->matchRequest($request), $pathInfo);
    }

    private function matchI18n(array $params, $url)
    {
        $request = $this->getRequest();

        // A localized route reports the name it was expanded from, which is the one the application
        // knows about.
        if (isset($params['_canonical_route'])) {
            $params['_route'] = $params['_canonical_route'];
            unset($params['_canonical_route']);
        }

        // A route available in every configured locale is flagged rather than carrying the whole
        // list, which would be dumped in both routing caches. Restore it here to keep the resolution
        // below uniform with the routes that do restrict their locales.
        $locales = $params['_locales'] ?? null;
        if (isset($params[I18nLoader::ALL_LOCALES])) {
            unset($params[I18nLoader::ALL_LOCALES]);

            if (null === $locales && $this->container->hasParameter('jms_i18n_routing.locales')) {
                $locales = $this->container->getParameter('jms_i18n_routing.locales');
            }
        }

        if (null !== $locales) {
            // The resolver comes first, not the request context. Symfony seeds the context with the
            // framework's default locale before the router runs - LocaleListener::setDefaultLocale()
            // does it at priority 100, ahead of the RouterListener - so a locale sitting in the
            // context is no longer evidence that anything chose it for this visitor. Reading it first
            // would mean never asking the resolver, and every host but the default one would resolve
            // to the wrong locale.
            $currentLocale = null;
            if (null !== $request) {
                $currentLocale = $this->localeResolver->resolveLocale($request, $locales);
            }

            // Outside a request - a sub-request, a console command - the context is all there is.
            if (!$currentLocale) {
                $currentLocale = $this->context->getParameter('_locale');
            }

            // If neither could determine a locale, then all efforts to make an informed decision
            // have failed. Just display something as a last resort.
            if (!$currentLocale && null !== $request) {
                $currentLocale = reset($locales);
            }

            if (!in_array($currentLocale, $locales, true)) {
                // TODO: We might want to allow the user to be redirected to the route for the given locale if
                //       it exists regardless of whether it would be on another domain, or the same domain.
                //       Below we assume that we do not want to redirect always.

                // if the available locales are on a different host, throw a ResourceNotFoundException
                if ($this->hostMap) {
                    // generate host maps
                    $hostMap = $this->hostMap;
                    $availableHosts = array_map(function($locale) use ($hostMap) {
                        return $hostMap[$locale];
                    }, $locales);

                    $differentHost = true;
                    foreach ($availableHosts as $host) {
                        if ($this->hostMap[$currentLocale] === $host) {
                            $differentHost = false;
                            break;
                        }
                    }

                    if ($differentHost) {
                        throw new ResourceNotFoundException(sprintf('The route "%s" is not available on the current host "%s", but only on these hosts "%s".',
                            $params['_route'], $this->hostMap[$currentLocale], implode(', ', $availableHosts)));
                    }
                }

                // no host map, or same host means that the given locale is not supported for this route
                throw new NotAcceptableLanguageException($currentLocale, $locales);
            }

            unset($params['_locales']);
            $params['_locale'] = $currentLocale;
        }

        // check if the matched route belongs to a different locale on another host
        if (isset($params['_locale'])
                && isset($this->hostMap[$params['_locale']])
                && $this->context->getHost() !== $host = $this->hostMap[$params['_locale']]) {
            if (!$this->redirectToHost) {
                throw new ResourceNotFoundException(sprintf(
                    'Resource corresponding to pattern "%s" not found for locale "%s".', $url, $this->getContext()->getParameter('_locale')));
            }

            return array(
                '_controller' => 'JMS\I18nRoutingBundle\Controller\RedirectController::redirectAction',
                'path'        => $url,
                'host'        => $host,
                'permanent'   => true,
                'scheme'      => $this->context->getScheme(),
                'httpPort'    => $this->context->getHttpPort(),
                'httpsPort'   => $this->context->getHttpsPort(),
                '_route'      => $params['_route'],
            );
        }

        // if we have no locale set on the route, we try to set one according to the localeResolver
        // if we don't do this all _internal routes will have the default locale on first request
        if (!isset($params['_locale'])
                && null !== $request
                && $locale = $this->localeResolver->resolveLocale(
                        $request,
                        $this->container->getParameter('jms_i18n_routing.locales'))) {
            $params['_locale'] = $locale;
        }

        return $params;
    }

    /**
     * @return Request|null
     */
    private function getRequest()
    {
        $request = null;
        if ($this->container->has('request_stack')) {
            $request = $this->container->get('request_stack')->getCurrentRequest();
        } elseif (method_exists($this->container, 'isScopeActive') && $this->container->isScopeActive('request')) {
            $request = $this->container->get('request');
        }

        return $request;
    }
}
