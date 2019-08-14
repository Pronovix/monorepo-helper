# Monorepo Helper Composer plugin

This Composer plugin finds all packages in a GIT monorepo and ensures every time when Composer installs _the latest
version of a package_ and the package is available inside the monorepo then the monorepo version of the package gets
installed (symlinked or copied from the monorepo) instead other available versions.

## Installation

```sh
$ composer global require pronovix/monorepo-helper
```

## How it works

The plugin tries to find the latest, valid, semantic versioning GIT tag in monorepo's remote origin. If it does not
find a valid semantic versioning tag it falls back to the latest dev version.

The identified version from GIT tags should be always the same as the latest version available version on Packagist or
in any other Composer repository from a library. For example, if the published version from the foo/bar package is
"1.0.0-alpha1" on Packagist then it is expected to have a "1.0.0-alpha1" GIT tag in your monorepo's remote origin.

The plugin can only fetch the latest tags from the remote origin if the runtime environment has access to the origin
repository (via API keys, SSH keys, etc.) If it does not have access to remote origin it is recommended to enable the
plugin's offline mode and before every `composer` command make sure that the latest tags from the remote origin get
fetched with `git fetch origin` manually or automatically.

## Configuration options

You can configure this plugin by setting the following configuration options in the root package's composer.json in
the [extra](https://getcomposer.org/doc/04-schema.md#extra) section under the `monorepo-helper` key or with the related
environment variables. The configuration in the root package's composer.json has the highest priority when the plugin's
configuration gets resolved.

|  `{{"extra": {"monorepo-helper": { "key": "value"}}}}` | Environment variable  | Type  | Default value | Description |
|---------------------------------------|-----------------------|-------|------------------|-------------|
| enabled  | PRONOVIX_MONOREPO_HELPER_ENABLED  | bool  | TRUE  | Allows to disable the plugin. Could be useful if there is an unfixed error in the plugin. |
| offline-mode  | PRONOVIX_MONOREPO_HELPER_OFFLINE_MODE  | bool  | FALSE  | If it is set to TRUE then the plugin does not try to fetch the latest tags from remote origin. You should ensures that latest tags are being fetched before the plugin actives. |
| max-discover-depth |  PRONOVIX_MONOREPO_HELPER_MAX_DISCOVERY_DEPTH |  int | 5 | The maximum package discovery depth from the monorepo's root. |
| excluded-directories |  PRONOVIX_MONOREPO_HELPER_EXCLUDED_DIRECTORIES | array | [] | Set of excluded directories (besides vendor) where the plugin should not look for monorepo packages. The environment variable should contain a comma separated list. |
| monorepo-root |  PRONOVIX_MONOREPO_HELPER_MONOREPO_ROOT | string | NULL | Could be useful it the plugin installed globally. You can specify the root of the monorepo.

Note: For boolean type configuration options use 1 or 0 in environment variables.

The plugin respects the [COMPOSER_MIRROR_PATH_REPOS](https://getcomposer.org/doc/03-cli.md#composer-mirror-path-repos) configuration.
