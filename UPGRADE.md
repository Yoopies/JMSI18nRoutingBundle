# Upgrade

## From the `perf-upgrade` branch to `perf-upgrade-native-naming`

### Route names changed

Localized routes used to be named `<locale>__RG__<route>`, with the bare `__RG__<route>` for the
node every remaining locale fell back on. They are now named the way Symfony names its own localized
routes:

| before                     | after            |
|----------------------------|------------------|
| `__RG__contact`            | `contact`        |
| `fr__RG__contact`          | `contact.fr`     |
| `fr_BE__RG__contact`       | `contact.fr_BE`  |
| `en_de__RG__contact`       | `contact.__i18n_en_de` (matching only, never generated) |

Nothing changes for the application code that calls `$router->generate('contact')` or reads
`$request->attributes->get('_route')`: both still use the plain route name. Only code that spelled
the prefix out has to be updated. Grep for `__RG__` to find it.

Known callers in this stack:

* **`Yoopies/Yoopies`** — `config/packages/fos_js_routing.yaml` lists routes to expose by name.
  Drop the `__RG__` prefix from every entry. **Required**: without it the entries match nothing and
  the routes disappear from the JavaScript bundle.
* **`Yoopies/Yoopies`** — `assets/js/services/fosRoutingFallback.js` rebuilds the locale walk
  client-side and has to follow the `<route>.<locale>` chain instead of the `<locale>__RG__` one.

`I18nLoader::ROUTING_PREFIX` **must not be removed**. `FOSJsRoutingBundle` references it upstream -
`ExposedRoutesExtractor::getPrefix()` returns `$locale.I18nLoader::ROUTING_PREFIX` whenever this
bundle is installed - so dropping it fatals the application. Nothing in this bundle uses it any
more; the prefix it produces simply no longer matches any route name, which is harmless because the
JavaScript client also tries the bare route name.

### Locales are walked part by part

A locale falls back one part at a time, and both `-` and `_` delimit a part: `fr_BE` falls back on
`fr`, and the per-company locales - written `fr_FR-MYCOMPANY` by `CompanyLocale` - fall back on
`fr_FR` and then on `fr`. Route names always join the parts with `_`, so the company locale above
looks for `<route>.fr_FR_MYCOMPANY`.

Symfony's own generator is coarser: it strips only what follows the first underscore, which would
send `fr_FR-MYCOMPANY` straight to `fr` and skip the country. `I18nRouter::generate()` therefore walks
locales of more than two parts itself, and hands everything else to Symfony untouched - so the
common case keeps costing nothing.

### Route defaults changed

Localized routes now carry `_canonical_route` (the name the route was expanded from) and always a
`_locale`. Symfony's generator needs that pair to drop the `_locale` parameter instead of appending
it to the query string, and `I18nRouter::matchI18n()` reports `_canonical_route` as the `_route`.

`_locales` is only set when a route accepts several locales but not all of them. A route accepting
every configured locale carries `_i18n_all_locales` instead, and the router reads the list back from
the `jms_i18n_routing.locales` parameter - spelling the list out on every untranslated route was the
single biggest contributor to the size of both routing caches.

If you match on those defaults yourself, read `_locales` and `_i18n_all_locales` together.

### `I18nLoader` takes the locales

`I18nLoader::__construct()` accepts the configured locales as an optional third argument. The
service definition passes `%jms_i18n_routing.locales%`. Without them the loader still works but
cannot tell that a route accepts every locale, so it falls back to writing the list out.

If you replaced `jms_i18n_routing.loader` with your own definition, add the argument.

### Routes restricted with `i18n_locales`

Those routes get one route per locale again, as upstream does, instead of going through the locale
tree. The tree's fallback node is reachable from any locale when generating, so it handed out URLs
for locales the route was not available in. They are rare enough that the extra routes do not
matter.

## Version requirements

| | before | after |
|---|---|---|
| PHP | `^7.4 \|\| ^8.0` | `^8.2` |
| Symfony | `^4.0 \|\| ^5.0 \|\| ^6.0` | `^5.4 \|\| ^6.0` |

PHP 8.2 matches what the application already requires. Symfony 4.x and 5.0-5.3 are gone.

The `^6.0` that used to be advertised had never worked - nothing contradicted it, because the test
suite had not run since the Symfony upgrade. The whole suite now passes on both a Symfony 5.4 stack
and a Symfony 6.4 one. Three things had to be fixed to get there, and they are worth knowing about
because two of them were silent.

**The router service asked for a container that no longer exists.** `jms_i18n_routing.router`
derives from `router.default`, whose first argument is `Psr\Container\ContainerInterface`. Symfony
used to alias that to the container itself, so the inherited argument happened to resolve. The alias
was deprecated in 5.1 and removed in 6.0, and the container then failed to compile. The service now
asks for `service_container` explicitly, which is what it was getting all along.

**The locale resolver had stopped being called.** Since Symfony 6.0,
`LocaleListener::setDefaultLocale()` seeds the request context with the framework's default locale
at priority 100 - ahead of the `RouterListener`. `matchI18n()` read the context first and only fell
back to the resolver when it was empty, so on Symfony 6 it always found the default locale sitting
there and never asked. Host, cookie and `Accept-Language` resolution were all dead, and with `hosts`
configured every request to a non-default host resolved to the default locale: a redirect to the
default host, or a `ResourceNotFoundException` when `redirect_to_host` is off. The resolver is now
consulted first whenever there is a request, and the context is the fallback for sub-requests and
console commands.

This one is worth re-reading if you subclass the router or implement `LocaleResolverInterface`: the
resolver is now asked on every match of a route shared by several locales, where it used to be asked
only when the context was empty. Routes belonging to a single locale never reach it, so the prefix
strategy is unaffected.

**`I18nRouter` redeclared `$defaultLocale`**, which `Router` has typed `?string` since 6.0 - and had
itself declared well before 5.4, so the redeclaration was only ever redundant.

The test suite no longer pulls the deprecated `symfony/symfony` metapackage, which was also masking
a mixed-version install; it lists the components it needs. `sensio/framework-extra-bundle` is gone
too: it is abandoned, and on Symfony 6 its `@Route("/", name = "homepage")` annotation was read as a
Symfony localized path, producing routes named `<route>.name` and `<route>.value`. The test
controller uses the `#[Route]` attribute and renders Twig itself.
