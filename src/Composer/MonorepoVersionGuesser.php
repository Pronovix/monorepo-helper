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

use Composer\Package\Version\VersionGuesser;
use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;
use vierbergenlars\SemVer\version;

/**
 * Guesses package versions inside the monorepo.
 */
final class MonorepoVersionGuesser
{
    /**
     * @var \Composer\Package\Version\VersionGuesser
     */
    private $versionGuesser;

    /**
     * The next semantic version for all packages inside the repo based on git tags.
     *
     * It is NULL, if it has not been detected by getNextVersion(). It is FALSE,
     * if it could not be detected by getNextVersion(). It is a non-empty string
     * if getNextVersion() could detected the next version from the git tags.
     *
     * @var bool|string|null
     *
     * @see getNextSemanticVersion()
     */
    private $_nextVersion;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Pronovix\MonorepoHelper\Composer\PluginConfiguration
     */
    private $configuration;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    private $process;

    /**
     * Absolute root path of the monorepo.
     *
     * @var string
     */
    private $monorepoRoot;

    /**
     * MonorepoVersionGuesser constructor.
     *
     * @param string $monorepoRoot
     * @param \Composer\Package\Version\VersionGuesser $versionGuesser
     * @param \Composer\Util\ProcessExecutor $process
     * @param \Pronovix\MonorepoHelper\Composer\PluginConfiguration $configuration
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(string $monorepoRoot, VersionGuesser $versionGuesser, ProcessExecutor $process, PluginConfiguration $configuration, LoggerInterface $logger)
    {
        $this->versionGuesser = $versionGuesser;
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->process = $process;
        $this->monorepoRoot = $monorepoRoot;
    }

    /**
     * Returns the version for a package inside the monorepo.
     *
     * @param array $package_data
     *   Parsed package data from composer.jons.
     * @param string $packageRoot
     *   Path to the package.
     *
     * @return string
     *   Package version.
     */
    public function getPackageVersion(array $package_data, string $packageRoot): string
    {
        // If package's composer.json defines the version bail out early.
        if (isset($package_data['version'])) {
            return $package_data['version'];
        }

        $version = $this->getNextSemanticVersion();
        if ($version) {
            // We need to imitate that the local package version is the next version of the library, because even if
            // \Composer\Repository\RepositoryManager::prependRepository() says that a plugin trump Packagist (Drupal
            // Packagist) this is not entirely true. The \Composer\DependencyResolver\Pool::addRepository()
            // differentiate repositories based on whether they extends \Composer\Repository\ComposerRepository or not
            // and if they do then they become "providerRepos". It means that they can provide the first available
            // version of a package in \Composer\DependencyResolver\Pool::computeWhatProvides() and other repos, like
            // this, can only add their package versions later to the list. Thanks for this implementation when
            // \Composer\Package\Version\VersionSelector::findBestCandidate() calls
            // \Composer\DependencyResolver\Pool::whatProvides() for a package then only the end of the list contains
            // the monorepo version of the package. Because of that, if the same version available on Packagist (Drupal
            // Packagist) that version wins in \Composer\Package\Version\VersionSelector::findBestCandidate() because
            // it was available earlier in the candidates list.
            return (string) $version;
        }

        // Fallback to what \Composer\Repository\PathRepository::initialize() does.
        $version = 'dev-master';
        $versionData = $this->versionGuesser->guessVersion($package_data, $packageRoot);
        if (null !== $versionData && '-dev' === substr($versionData['version'], -4) && preg_match('{\.9{7}}', $versionData['version'])) {
            $version = preg_replace('{(\.9{7})+}', '.x', $versionData['version']);
        }

        return $version;
    }

    /**
     * Gets the next semantic version for all packages inside the monorepo.
     *
     * @return bool|string
     *   The next semantic version string or FALSE if the the latest semantic versioning tag could not be identified
     *   on remote origin.
     */
    private function getNextSemanticVersion()
    {
        if (null === $this->_nextVersion) {
            $latest_semver_tag = $this->getLatestSemanticVersionGitTag();
            if (null !== $latest_semver_tag) {
                $version = new version($latest_semver_tag);
                if ($prerelease = $version->getPrerelease()) {
                    // Manually calculate next pre-release tag, because $version->inc('prerelease'); creates
                    // "alpha1.0" from "alpha1".
                    $next_pre_release = preg_replace_callback('/^([^0-9]*)([0-9]+)$/', function (array $matches) {
                        unset($matches[0]);
                        $matches[2] = (int) $matches[2];
                        ++$matches[2];

                        return implode('', $matches);
                    }, (string) $prerelease[0]);
                    $this->_nextVersion = "{$version->getMajor()}.{$version->getMinor()}.{$version->getPatch()}-{$next_pre_release}";
                } else {
                    $this->_nextVersion = $version->inc('patch')->getVersion();
                }
                $this->logger->info("'{version}' is the next semantic version for all packages inside the monorepo.", ['version' => $this->_nextVersion]);
            } else {
                $this->_nextVersion = false;
            }
        }

        return $this->_nextVersion;
    }

    /**
     * Gets the latest tag from remote origin that is a valid semantic versioning tag.
     *
     * @return string|null
     *   The latest tag or NULL if the remote origin could not be fetched or no tag found.
     */
    private function getLatestSemanticVersionGitTag(): ?string
    {
        $latest_semver_tag = null;
        $output = '';
        // Try to fetch the remove origin firsts if offline mode is not enabled.
        if (!$this->configuration->isOfflineMode() && 0 !== $this->process->execute('git fetch origin', $output, $this->monorepoRoot)) {
            $this->logger->critical('Unable to fetch remote origin. Error: {error}', ['error' => $this->process->getErrorOutput()]);
        }

        // Proper sorting is possible for local and remote tags like this.
        // "suffix=-" prevents 2.0-rc listed "after" 2.0
        if (0 === $this->process->execute("git -c 'versionsort.suffix=-' for-each-ref --sort='-version:refname' --format='%(refname:short)' refs/tags", $output)) {
            if (empty(trim($output))) {
                $this->logger->info('No tag found in the local repository.');
            } else {
                $sorted_local_remote_tags = $this->process->splitLines($output);
                $this->logger->info('The following local and remote tags found: {tags}.', ['tags' => implode(', ', $sorted_local_remote_tags)]);
                if ($this->configuration->isOfflineMode()) {
                    $remote_only_tags = $sorted_local_remote_tags;
                } else {
                    // But (proper) sorting is not possible if we would like to list remote tags _only_.
                    // Also we purposefully do not check the exit code of this process because it does
                    // not provide additional information.https://git-scm.com/docs/git-ls-remote.html
                    $this->process->execute('git ls-remote -t --refs --exit-code origin', $output, $this->monorepoRoot);

                    if (empty(trim($output))) {
                        $message = 'No tags found on remote origin.';
                        if (!empty($sorted_local_remote_tags)) {
                            $message .= ' All tags found earlier were local only.';
                        }
                        $this->logger->info($message);

                        // We return null here because if someone would like to use the local only tags
                        //then they should enable the offline tags.
                        return null;
                    } else {
                        $unsorted_remote_tags_only = array_map(static function (string $line) {
                            [, $ref] = preg_split('/\s+/', $line);
                            [, , $tag] = explode('/', $ref);

                            return $tag;
                        }, $this->process->splitLines($output));
                        $remote_only_tags = array_intersect($sorted_local_remote_tags, $unsorted_remote_tags_only);
                        $this->logger->info('The following tags found on remote origin: {tags}.', ['tags' => implode(', ', $remote_only_tags)]);
                    }
                }

                // Find the latest semantic versioning tag on the remote. Array is already in a descending
                // order.
                foreach ($remote_only_tags as $remote_only_tag) {
                    try {
                        new version($remote_only_tag);
                        $latest_semver_tag = $remote_only_tag;
                        $this->logger->info("'{tag}' is the highest semantic versioning tag in remote origin.", ['tag' => $latest_semver_tag]);
                        break;
                    } catch (\Exception $e) {
                        $this->logger->info("Skipping '{tag}' remote tag because it is not a valid semantic versioning tag.", ['tag' => $remote_only_tag]);
                    }
                }
            }
        }

        return $latest_semver_tag;
    }
}
