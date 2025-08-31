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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ArrayRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Monorepo Helper plugin definition.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var \Composer\Repository\RepositoryInterface
     */
    private $repository;

    private string $monorepoPath = '';

    private LoggerInterface $logger;

    private PluginConfiguration $configuration;

    public function __construct()
    {
        // These are going to replaced with real objects in activate().
        $this->repository = new ArrayRepository([]);
        $this->logger = new NullLogger();
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        // On installation, if these class dependencies have not been installed
        // earlier then the installation process could fail because the autoloader
        // has not been updated yet with the newly installed dependencies when
        // activate() runs the first time.
        // @see https://getcomposer.org/doc/articles/plugins.md#plugin-autoloading
        if (!class_exists('\Pronovix\ComposerLogger\Logger')) {
            $loggerClassFile = $composer->getConfig()->get('vendor-dir') . '/pronovix/composer-logger/src/Logger.php';
            if (file_exists($loggerClassFile)) {
                require_once $loggerClassFile;
            } else {
                $io->writeError('\Pronovix\ComposerLogger\Logger was missing and it could not be autoloaded.');

                return;
            }
        }
        if (!class_exists('\Symfony\Component\Filesystem\Path')) {
            $SymfonyFileSystemPathClassFile = $composer->getConfig()->get('vendor-dir') . '/symfony/filesystem/Path.php';
            if (file_exists($SymfonyFileSystemPathClassFile)) {
                require_once $SymfonyFileSystemPathClassFile;
            } else {
                $io->writeError('\Symfony\Component\Filesystem\Path file could not be autoloaded.');

                return;
            }
        }

        $this->logger = new Logger($io);
        $this->configuration = new PluginConfiguration($composer);

        if (!$this->configuration->isEnabled()) {
            $this->logger->info('Plugin is configured to be disabled.');

            return;
        }

        $process = $composer->getLoop()->getProcessExecutor();
        $monorepoRoot = null;
        $output = '';

        if (null === $this->configuration->getForcedMonorepoRoot()) {
            if (0 === $process->execute('git rev-parse --absolute-git-dir', $output)) {
                $monorepoRoot = dirname(trim($output));
                $this->logger->info('Detected monorepo root: {dir}', ['dir' => $monorepoRoot]);
            }

            if (null === $monorepoRoot) {
                $this->logger->info('Plugin is disabled because no GIT root found in {dir} directory', ['dir' => realpath(getcwd())]);

                return;
            }
        } else {
            $monorepoRootBasePathCandidates = [];
            if (realpath(Factory::getComposerFile())) {
                $monorepoRootBasePathCandidates[] = dirname(realpath(Factory::getComposerFile()));
            }
            $monorepoRootBasePathCandidates[] = $composer->getConfig()->get('home');
            foreach ($monorepoRootBasePathCandidates as $monorepoRootBasePathCandidate) {
                $this->logger->debug('Monorepo base path candidate is {directory}.', ['directory' => $monorepoRootBasePathCandidate]);
                $monorepoRoot = Path::makeAbsolute($this->configuration->getForcedMonorepoRoot(), $monorepoRootBasePathCandidate);
                $this->logger->debug('Monorepo root candidate is {directory}.', ['directory' => $monorepoRoot]);

                if (is_dir($monorepoRoot . '/.git')) {
                    $this->logger->warning('Forced monorepo root is {directory}.', ['directory' => $monorepoRoot]);
                    break;
                }
                $monorepoRoot = null;
            }

            if (null === $monorepoRoot) {
                $this->logger->info('Plugin is disabled because forced monorepo root does not seem to be a valid GIT root.');

                return;
            }
        }

        $this->monorepoPath = $monorepoRoot;
        $versionParser = new VersionParser();
        $composerVersionGuesser = new VersionGuesser($composer->getConfig(), $process, $versionParser);
        $monorepoVersionGuesser = new MonorepoVersionGuesser($monorepoRoot, $composerVersionGuesser, $process, $this->configuration, $this->logger);
        $this->repository = new MonorepoRepository($monorepoRoot, $this->configuration, new ArrayLoader($versionParser, true), $process, $monorepoVersionGuesser, $composerVersionGuesser, $composer->getPackage(), $this->logger);
        // This ensures that the monorepo repository provides trumps both Packagist and Drupal packagist, so even if
        // the same version is available in multiple repositories the monorepo versions wins. Well, this is not entirely
        // true, it wins for dev versions but for >= alpha versions a different rule applies. See more details in
        // \Pronovix\MonorepoHelper\Composer\MonorepoVersionGuesser.
        $composer->getRepositoryManager()->prependRepository($this->repository);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => [
                ['onCommand', 0],
            ],
            PackageEvents::POST_PACKAGE_INSTALL => [['onPackageInstall']],
            PackageEvents::POST_PACKAGE_UPDATE => [['onPackageUpdate']],
            PackageEvents::PRE_PACKAGE_UNINSTALL => [['onPackageUninstall']],
        ];
    }

    public function onPackageInstall(PackageEvent $event): void
    {
        if (!$this->configuration->isEnabled()) {
            return;
        }
        $this->registerPackageWithFrontendAssetsInYarnWorkspaces($event);
    }

    public function onPackageUpdate(PackageEvent $event): void
    {
        if (!$this->configuration->isEnabled()) {
            return;
        }
        $this->registerPackageWithFrontendAssetsInYarnWorkspaces($event);
    }

    public function onPackageUninstall(PackageEvent $event): void
    {
        if (!$this->configuration->isEnabled()) {
            return;
        }
        $this->deregisterPackageWithFrontendAssetsFromYarnWorkspaces($event);
    }

    /**
     * Reacts to Composer commands.
     */
    public function onCommand(CommandEvent $event): void
    {
        if (!$this->configuration->isEnabled()) {
            return;
        }
        if (null === $this->repository) {
            return;
        }

        if (!function_exists('proc_open')) {
            $this->repository->disable('Plugin is disabled because "proc_open" function does not exist.');

            return;
        }
        if ($event->getInput()->hasOption('prefer-lowest') && $event->getInput()->getOption('prefer-lowest')) {
            $this->repository->disable('Plugin is disabled on prefer-lowest installs.');
        }
    }

    private function registerPackageWithFrontendAssetsInYarnWorkspaces(PackageEvent $event): void
    {
        if ($event->getOperation() instanceof InstallOperation) {
            $package = $event->getOperation()->getPackage();
        } elseif ($event->getOperation() instanceof UpdateOperation) {
            $package = $event->getOperation()->getTargetPackage();
        } else {
            return;
        }
        assert($package instanceof PackageInterface);

        $installationManager = $event->getComposer()->getInstallationManager();
        $packagePath = $installationManager->getInstallPath($package);

        if (null !== $packagePath && $this->packageHasFrontendAssets($packagePath)) {
            $this->logger->debug('Frontend assets detected in the {package} package.', ['package' => $package->getName()]);

            $packageJsonPath = $this->monorepoPath . '/package.json';
            $filesystem = new Filesystem();

            if (!file_exists($packageJsonPath)) {
                $packageJsonContent = [
                    'name' => 'pronovix-product',
                    'private' => true,
                    'license' => 'GPL-2.0-or-later',
                    'workspaces' => [],
                ];

                $filesystem->dumpFile($packageJsonPath, json_encode($packageJsonContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->logger->info('Created package.json file at {path} path', ['path' => $packageJsonPath]);
            }

            try {
                $packageJsonContent = method_exists($filesystem, 'readFile') ? $filesystem->readFile($packageJsonPath) : file_get_contents($packageJsonPath);
                $packageJson = json_decode($packageJsonContent, true, flags: JSON_THROW_ON_ERROR);

                $relativePackagePath = $filesystem->makePathRelative($filesystem->readlink($packagePath, true), $this->monorepoPath);
                $relativeFrontendDirPathInsidePackage = $relativePackagePath . 'frontend';

                if (!in_array($relativeFrontendDirPathInsidePackage, $packageJson['workspaces'] ?? [], true)) {
                    $packageJson['workspaces'][] = $relativeFrontendDirPathInsidePackage;
                }

                $filesystem->dumpFile($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $this->logger->info('Registered {package} package as workspace in package.json at {path} path.', ['package' => $package->getName(), 'path' => $packageJsonPath]);
            } catch (\Exception $e) {
                $this->logger->error('The package.json at {path} path could not be decoded. Reason: {reason}', ['reason' => $e->getmessage(), 'path' => $packageJsonPath]);
            }
        }
    }

    private function deregisterPackageWithFrontendAssetsFromYarnWorkspaces(PackageEvent $event): void
    {
        if (!($event->getOperation() instanceof UninstallOperation)) {
            return;
        }

        $package = $event->getOperation()->getPackage();
        $installationManager = $event->getComposer()->getInstallationManager();
        $packagePath = $installationManager->getInstallPath($package);

        // Check if package has frontend assets before it gets removed
        if (null !== $packagePath && $this->packageHasFrontendAssets($packagePath)) {
            $this->logger->debug('Deregistering workspace related to {package} package.', ['package' => $package->getName()]);

            $packageJsonPath = $this->monorepoPath . '/package.json';
            $filesystem = new Filesystem();

            if (!file_exists($packageJsonPath)) {
                $this->logger->info('No package.json file at {path} path to deregister workspace from.', ['path' => $packageJsonPath]);

                return;
            }

            try {
                $packageJsonContent = method_exists($filesystem, 'readFile') ? $filesystem->readFile($packageJsonPath) : file_get_contents($packageJsonPath);
                $packageJson = json_decode($packageJsonContent, true, flags: JSON_THROW_ON_ERROR);

                $relativePackagePath = $filesystem->makePathRelative($filesystem->readlink($packagePath, true), $this->monorepoPath);
                $relativeFrontendDirPathInsidePackage = $relativePackagePath . 'frontend';

                if (isset($packageJson['workspaces']) && is_array($packageJson['workspaces'])) {
                    $key = array_search($relativeFrontendDirPathInsidePackage, $packageJson['workspaces'], true);
                    if (false !== $key) {
                        unset($packageJson['workspaces'][$key]);
                        // Re-index array to maintain JSON array format
                        $packageJson['workspaces'] = array_values($packageJson['workspaces']);

                        $filesystem->dumpFile($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                        $this->logger->info('Deregistered {package} package as workspace from package.json at {path} path.', ['package' => $package->getName(), 'path' => $packageJsonPath]);
                    } else {
                        $this->logger->debug('The {package} package as workspace was not registered in package.json at {path} path.', ['package' => $package->getName(), 'path' => $packageJsonPath]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to deregister {package} package from package.json at {path} path could not be decoded. Reason: {reason}', ['reason' => $e->getmessage(), 'package' => $package->getName(), 'path' => $packageJsonPath]);
            }
        }
    }

    private function packageHasFrontendAssets(string $packagePath): bool
    {
        return file_exists($packagePath . '/frontend/package.json');
    }
}
