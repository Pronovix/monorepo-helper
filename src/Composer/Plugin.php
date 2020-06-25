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
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Composer\Util\ProcessExecutor;

/**
 * Monorepo Helper plugin definition.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var \Pronovix\MonorepoHelper\Composer\MonorepoRepository|null
     */
    private $repository;

    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        // On global installations, if the pronovix/composer-logger library
        // has not been installed earlier then the install could fail because
        // the autoloader has not been updated yet with the newly installed
        // dependency.
        if (!class_exists('\Pronovix\ComposerLogger\Logger')) {
            $loggerFile = $composer->getConfig()->get('vendor-dir') . '/pronovix/composer-logger/src/Logger.php';
            if (file_exists($loggerFile)) {
                require_once $loggerFile;
            } else {
                $io->writeError('composer-logger was missing and it could not be autoloaded.');

                return;
            }
        }

        $logger = new Logger($io);
        $configuration = new PluginConfiguration($composer);

        if (!$configuration->isEnabled()) {
            $logger->info('Plugin is configured to be disabled.');

            return;
        }

        $process = new ProcessExecutor($io);
        $monorepoRoot = $configuration->getForcedMonorepoRoot();
        $output = '';

        if (null === $monorepoRoot) {
            if (0 === $process->execute('git rev-parse --absolute-git-dir', $output)) {
                $monorepoRoot = dirname(trim($output));
                $logger->info('Detected monorepo root: {dir}', ['dir' => $monorepoRoot]);
            }

            if (null === $monorepoRoot) {
                $logger->info('Plugin is disabled because no GIT root found in {dir} directory', ['dir' => realpath(getcwd())]);

                return;
            }
        } else {
            $logger->warning('Forced monorepo root is {directory}.', ['directory' => $monorepoRoot]);
            $filesystem = new ComposerFilesystem($process);
            $monorepoRoot = $filesystem->normalizePath($filesystem->isAbsolutePath($monorepoRoot) ? $monorepoRoot : getcwd() . '/' . $monorepoRoot);

            if (!is_dir($monorepoRoot . '/.git')) {
                $logger->info('Plugin is disabled because forced monorepo root does not seem to be a valid GIT root.');

                return;
            }
        }

        $versionParser = new VersionParser();
        $composerVersionGuesser = new VersionGuesser($composer->getConfig(), $process, $versionParser);
        $monorepoVersionGuesser = new MonorepoVersionGuesser($monorepoRoot, $composerVersionGuesser, $process, $configuration, $logger);
        $this->repository = new MonorepoRepository($monorepoRoot, $configuration, new ArrayLoader($versionParser, true), $process, $monorepoVersionGuesser, $composerVersionGuesser, $logger);
        // This ensures that the monorepo repository provides trumps both Packagist and Drupal packagist, so even if
        // the same version is available in multiple repositories the monorepo versions wins. Well, this is not entirely
        // true, it wins for dev versions but for >= alpha versions a different rule applies. See more details in
        // \Pronovix\MonorepoHelper\Composer\MonorepoVersionGuesser.
        $composer->getRepositoryManager()->prependRepository($this->repository);
    }

    /**
     * @inheritDoc
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @inheritDoc
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => [
                ['onCommand', 0],
            ],
        ];
    }

    /**
     * Reacts to Composer commands.
     *
     * @param \Composer\Plugin\CommandEvent $event
     */
    public function onCommand(CommandEvent $event): void
    {
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
}
