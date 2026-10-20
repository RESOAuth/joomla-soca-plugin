# SOCA for Joomla

Installable CIMD OpenID Connect sign-in for Joomla, with ES256 client assertions, PKCE S256, deny-by-default admission and explicit account links. **Version 0.1.0 is an evaluation preview**, with [remaining requirements](docs/requirements.md) documented per ID.

Joomla front-end browser login only. Back-end administrators retain local authentication; SOCA rejects Super Users and accounts with administrator login privileges.

Run `composer install --no-dev` and `python3 bin/package.py`. Install `build/pkg_soca-0.1.0.zip` with Joomla Extensions, Install, or `php cli/joomla.php extension:install --path=...`. Enable the SOCA system, authentication and user plugins. Edit the SOCA system plugin profile JSON in the native Plugins manager.

Mount a persistent P-256 signing key using `SOCA_PRIVATE_KEY_FILE` and set the canonical HTTPS origin plus admission rules. Keep the candidate disabled until public document checks and an operator test login pass for that exact revision. See [configuration](docs/configuration.md), [operations and recovery](docs/operations.md), and [verification](docs/verification.md).

```sh
composer install
composer test
CONTAINER_ENGINE=podman bash demo/run.sh test
CONTAINER_ENGINE=podman bash demo/run.sh serve
```

The disposable local demo installs the actual host in PHP containers. Local HTTP never bypasses production HTTPS or public-network checks; live SSO activation requires publicly reachable HTTPS metadata or a checked proxy profile. Tests include signed synthetic-provider flows and native host account/session checks. No live-provider login has been completed.

Runtime: Joomla 5.4 on PHP 8.3+, MySQL/MariaDB; native smoke tested with Joomla 5.4.0 and MariaDB 11.4. New users receive the native Registered group (ID 2) only; provisioning refuses this group if it has administrator privileges. Configurable group selection is not yet implemented.

CI tests protocol/bridge cases on PHP 8.3/8.4, installs the native host, audits Composer dependencies and builds packages/SBOMs. Dependabot tracks Composer, actions and the demo image. CodeQL checks Python packaging and workflow code; [CodeQL does not analyse PHP](https://codeql.github.com/docs/codeql-overview/supported-languages-and-frameworks/). See [security reporting](SECURITY.md).
