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
* **`Yoopies/FOSJsRoutingBundle`** — `ExposedRoutesExtractor::getPrefix()` returned
  `<locale>__RG__`, and `Resources/public/js/router.js` rebuilt the same walk client-side. Both now
  follow the `<route>.<locale>` chain.

`I18nLoader::ROUTING_PREFIX` still exists so that no update order can produce a fatal error, but it
is deprecated and nothing in the bundle uses it any more.

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
| Symfony | `^4.0 \|\| ^5.0 \|\| ^6.0` | `^5.4` |

PHP 8.2 matches what the application already requires.

Symfony 4.x and 5.0-5.3 are gone. **The `^6.0` that used to be advertised never worked** - it was
never exercised, since the test suite had not run since the Symfony upgrade. On a consistent
Symfony 6.4 stack the 45 unit tests pass and the 6 functional tests fail: `jms_i18n_routing.router`
is a child of `router.default`, whose first argument became a service-subscriber locator
(`Psr\Container\ContainerInterface`). That locator is only resolved for the tagged parent
definition, and a child definition does not inherit tags, so the container fails to compile.

Supporting Symfony 6 therefore means changing how the router service is declared - swapping the
class on `router.default` rather than deriving a service from it, which is what the application's
own `RouterCompilerPass` already does one level up. That is a separate change; the constraint says
`^5.4` until it is done.

The test suite no longer pulls the deprecated `symfony/symfony` metapackage, listing the components
it actually needs instead.
