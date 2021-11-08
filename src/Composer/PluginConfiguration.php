<?php

declare(strict_types=1);

/**
 * Copyright (C) 2019 PRONOVIX GROUP BVBA.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *  *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *  *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301,
 * USA.
 */

namespace Pronovix\MonorepoHelper\Composer;

use Composer\Composer;

/**
 * Value object that stores this plugin's configuration.
 */
final class PluginConfiguration
{
    /** @var int */
    private const DEFAULT_PACKAGE_DISCOVERY_DEPTH = 5;

    /** @var bool */
    private $offlineMode;

    /** @var int */
    private $maxDiscoveryDepth;

    /** @var string[] */
    private $excludedDirectories;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var string|null
     */
    private $forcedMonorepoRoot;

    /**
     * PluginConfiguration constructor.
     *
     * @param \Composer\Composer $composer
     *
     * @psalm-suppress PossiblyFalseArgument
     */
    public function __construct(Composer $composer)
    {
        $extra = $composer->getPackage()->getExtra();
        $monorepo_helper = $extra['monorepo-helper'] ?? [];
        $this->enabled = (bool) ($monorepo_helper['enabled'] ?? false === getenv('PRONOVIX_MONOREPO_HELPER_ENABLED') ? true : (bool) getenv('PRONOVIX_MONOREPO_HELPER_ENABLED'));
        $this->offlineMode = (bool) ($monorepo_helper['offline-mode'] ?? false === getenv('PRONOVIX_MONOREPO_HELPER_OFFLINE_MODE') ? false : (bool) getenv('PRONOVIX_MONOREPO_HELPER_OFFLINE_MODE'));
        // 0 as max discovery depth is not valid.
        $this->maxDiscoveryDepth = (int) ($monorepo_helper['max-discover-depth'] ?? getenv('PRONOVIX_MONOREPO_HELPER_MAX_DISCOVERY_DEPTH')) ?: self::DEFAULT_PACKAGE_DISCOVERY_DEPTH;
        $this->excludedDirectories = (is_array($monorepo_helper['excluded-directories'] ?? null) ? $monorepo_helper['excluded-directories'] : false === getenv('PRONOVIX_MONOREPO_HELPER_EXCLUDED_DIRECTORIES')) ? [] : explode(',', getenv('PRONOVIX_MONOREPO_HELPER_EXCLUDED_DIRECTORIES'));
        $this->forcedMonorepoRoot = ($monorepo_helper['monorepo-root'] ?? null) ?? (false === getenv('PRONOVIX_MONOREPO_HELPER_MONOREPO_ROOT') ? null : getenv('PRONOVIX_MONOREPO_HELPER_MONOREPO_ROOT'));
    }

    /**
     * Set of directories where the monorepo helper should not try to find packages.
     *
     * @return string[]
     *   Array of directory names.
     */
    public function getExcludedDirectories(): array
    {
        return $this->excludedDirectories;
    }

    /**
     * The maximum lookup depth from the monorepo's root for package discovery.
     *
     * @return int
     */
    public function getMaxDiscoveryDepth(): int
    {
        return $this->maxDiscoveryDepth;
    }

    /**
     * Enables/disables fetching changes from remote origin.
     *
     * @return bool
     */
    public function isOfflineMode(): bool
    {
        return $this->offlineMode;
    }

    /**
     * Enables/disables the plugin.
     *
     * It allows to disable the plugin in case of an error.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Non-validated enforced monorepo root.
     *
     * @return string|null
     */
    public function getForcedMonorepoRoot(): ?string
    {
        return $this->forcedMonorepoRoot;
    }
}
