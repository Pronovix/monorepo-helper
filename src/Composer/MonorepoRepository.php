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

use Composer\Json\JsonFile;
use Composer\Package\Loader\LoaderInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Repository\ArrayRepository;
use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Repository plugin that discovers packages inside a monorepo.
 */
final class MonorepoRepository extends ArrayRepository
{
    /**
     * Absolute root path of the monorepo.
     *
     * @var string
     */
    private $monorepoRoot;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    private $process;

    /**
     * @var \Composer\Package\Loader\LoaderInterface
     */
    private $loader;

    /**
     * @var bool
     */
    private $enabled = true;

    /**
     * @var \Pronovix\MonorepoHelper\Composer\Logger|\Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Pronovix\MonorepoHelper\Composer\PluginConfiguration
     */
    private $configuration;
    /**
     * @var \Pronovix\MonorepoHelper\Composer\MonorepoVersionGuesser
     */
    private $monorepoVersionGuesser;

    /**
     * @var \Composer\Package\Version\VersionGuesser
     */
    private $composerVersionGuesser;

    /**
     * @var \Composer\Package\RootPackageInterface
     */
    private $rootPackage;

    /**
     * MonorepoRepository constructor.
     *
     * @param \Pronovix\MonorepoHelper\Composer\PluginConfiguration $configuration
     * @param \Pronovix\MonorepoHelper\Composer\MonorepoVersionGuesser $monorepoVersionGuesser
     */
    public function __construct(string $monorepoRoot, PluginConfiguration $configuration, LoaderInterface $loader, ProcessExecutor $process, MonorepoVersionGuesser $monorepoVersionGuesser, VersionGuesser $composerVersionGuesser, RootPackageInterface $rootPackage, LoggerInterface $logger)
    {
        $this->monorepoRoot = $monorepoRoot;
        $this->configuration = $configuration;
        $this->loader = $loader;
        $this->process = $process;
        $this->monorepoVersionGuesser = $monorepoVersionGuesser;
        $this->composerVersionGuesser = $composerVersionGuesser;
        $this->rootPackage = $rootPackage;
        $this->logger = $logger;

        parent::__construct();
    }

    /**
     * Disables the repository handler.
     *
     * @param string $reason
     *   Explanation why the repository handler got disabled.
     */
    public function disable(string $reason): void
    {
        $this->enabled = false;
        $this->logger->info($reason);
    }

    /**
     * @see \Composer\Repository\PathRepository::initialize()
     */
    protected function initialize(): void
    {
        parent::initialize();

        if ($this->enabled) {
            if ($this->configuration->isOfflineMode()) {
                $this->logger->warning('Offline mode is active.');
            }

            $output = '';
            $packageDistReference = null;
            if (0 === $this->process->execute('git log -n1 --pretty=%H', $output, $this->monorepoRoot)) {
                $packageDistReference = trim($output);
            }

            // Prefer symlinking instead of copying if the environment variable is not set.
            $transport_as_symlink = false === getenv('COMPOSER_MIRROR_PATH_REPOS') ? true : !(bool) getenv('COMPOSER_MIRROR_PATH_REPOS');
            if (false === $transport_as_symlink) {
                $this->logger->warning('Packages are going to be copied instead of symlinked.');
            }

            foreach ($this->getPackageRoots() as $packageRoot) {
                $composerFilePath = $packageRoot . DIRECTORY_SEPARATOR . 'composer.json';

                $json = file_get_contents($composerFilePath);
                $package_data = JsonFile::parseJson($json, $composerFilePath);
                $package_data['dist'] = [
                    'type' => 'path',
                    'url' => $packageRoot,
                    'reference' => sha1($json),
                ];
                $package_data['transport-options'] = ['symlink' => $transport_as_symlink];

                $package_versions_to_register = [
                  // Register the root package's version as one available version.
                  // It could happen that the latest tag in the repo (and the next one) is
                  // one or more major versions ahead than the current root package version.
                  // E.g.: root package version is 2.x-dev but the latest tag is >= 3.0.0-alpha1.
                  $this->rootPackage->getVersion(),
                ];
                // The default version guesser is going to guess based on VCS.
                $composerVersionGuess = $this->composerVersionGuesser->guessVersion($package_data, $packageRoot);
                if (isset($composerVersionGuess['feature_pretty_version'])) {
                    $composerPrettyVersionGuess = $composerVersionGuess['feature_pretty_version'];
                } elseif (isset($composerVersionGuess['pretty_version'])) {
                    $composerPrettyVersionGuess = $composerVersionGuess['pretty_version'];
                }
                // This resolves the aliasing problem of the "master" branch. Master branch (among some others) is not
                // considered as feature branch therefore it always gets a special care.
                // @see \Composer\Semver\VersionParser::normalizeBranch()
                // @see \Composer\Semver\VersionParser::normalize()
                if (isset($composerPrettyVersionGuess)) {
                    // Register the unmodified feature pretty version as an available package version.
                    $package_versions_to_register[] = $composerPrettyVersionGuess;
                    if (false === strpos($composerPrettyVersionGuess, 'dev-') && isset($package_data['extra']['branch-alias'][$composerPrettyVersionGuess])) {
                        $package_versions_to_register[] = $package_data['extra']['branch-alias'][$composerPrettyVersionGuess];
                    }
                }

                $package_versions_to_register[] = $this->monorepoVersionGuesser->getPackageVersion($package_data, $packageRoot);

                foreach ($package_versions_to_register as $version) {
                    $package_data['version'] = $version;
                    if ($packageDistReference) {
                        $package_data['dist']['reference'] = trim($output);
                    }

                    /* @var \Composer\Package\Package $package */
                    try {
                        $package = $this->loader->load($package_data);
                    } catch (\Exception $e) {
                        // \Composer\Package\Loader\ArrayLoader::load() can thrown an exception even if it is not defined
                        // in the interface.
                        $this->logger->error('Unable to load package data from {file} file. Error: {error}.', ['file' => $composerFilePath, 'error' => $e->getMessage()]);
                        continue;
                    }
                    $this->addPackage($package);
                    $this->logger->info('Added {package} {type} as {version} version from the monorepo.', ['package' => $package->getPrettyName(), 'type' => $package->getType(), 'version' => $package->getPrettyVersion()]);
                }
            }
        }
    }

    /**
     * Get a list of all subpackage directories.
     *
     * @return \Generator
     *   Array of subpackage directories.
     */
    private function getPackageRoots(): \Generator
    {
        $finder = new Finder();
        $projects = $finder
            ->in($this->monorepoRoot)
            ->depth("<= {$this->configuration->getMaxDiscoveryDepth()}")
            ->notPath('vendor')
            ->files()->name('composer.json');

        // BC compatibility with symfony/finder 3.4 where noPath() only accepted a string.
        foreach ($this->configuration->getExcludedDirectories() as $excludedDirectory) {
            $projects->exclude($excludedDirectory);
        }

        foreach ($projects as $project) {
            /* @var $project \SplFileInfo */
            yield $project->getPath();
        }
    }
}
