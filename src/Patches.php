<?php
namespace cweagans\Composer;

class Patches implements \Composer\Plugin\PluginInterface, \Composer\EventDispatcher\EventSubscriberInterface
{
    /**
     * @var \Composer\Composer $composer
     */
    protected $composer;

    /**
     * @var \Composer\IO\IOInterface $io
     */
    protected $io;

    /**
     * @var \Composer\EventDispatcher\EventDispatcher $eventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var \Composer\Util\ProcessExecutor $executor
     */
    protected $executor;

    /**
     * @var array $installedPatches
     */
    protected $installedPatches;

    /**
     * @var array $packagesByName
     */
    protected $packagesByName;

    /**
     * @var array $excludedPatches
     */
    protected $excludedPatches;

    /**
     * @var array
     */
    protected $packagesToReinstall = array();

    /**
     * Note that postInstall is locked to autoload dump instead of post-install. Reason for this is that
     * post-install comes after auto-loader generation which means that in case patches target class
     * namespaces or class names, the auto-loader will not get those changes applied to it correctly.
     */
    public static function getSubscribedEvents()
    {
        return array(
            \Composer\Installer\PackageEvents::POST_PACKAGE_UNINSTALL => 'removePatches',
            \Composer\Installer\PackageEvents::PRE_PACKAGE_INSTALL => 'resetAppliedPatches',
            \Composer\Installer\PackageEvents::PRE_PACKAGE_UPDATE => 'resetAppliedPatches',
            \Composer\Script\ScriptEvents::PRE_AUTOLOAD_DUMP => 'postInstall'
        );
    }

    public function activate(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->eventDispatcher = $composer->getEventDispatcher();
        $this->executor = new \Composer\Util\ProcessExecutor($this->io);
        $this->installedPatches = array();
    }

    public function resetAppliedPatches(\Composer\Installer\PackageEvent $event)
    {
        foreach ($event->getOperations() as $operation) {
            if ($operation->getJobType() != 'install') {
                continue;
            }

            $package = $this->getPackageFromOperation($operation);
            $extra = $package->getExtra();

            unset($extra['patches_applied']);

            $package->setExtra($extra);
        }
    }

    protected function preparePatchDefinitions($patches, $ownerPackage = null)
    {
        $patchesPerPackage = array();

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        if ($ownerPackage) {
            $manager = $this->composer->getInstallationManager();
            $patchOwnerPath = $manager->getInstaller($ownerPackage->getType())->getInstallPath($ownerPackage);
        } else {
            $patchOwnerPath = false;
        }

        if (!$this->packagesByName) {
            $this->packagesByName = [];
            $packageRepository = $this->composer->getRepositoryManager()->getLocalRepository();
            foreach ($packageRepository->getPackages() as $package) {
                $this->packagesByName[$package->getName()] = $package;
            }
        }

        $excludedPatches = $this->getExcludedPatches();

        foreach ($patches as $patchTarget => $packagePatches) {
            if (!isset($patchesPerPackage[$patchTarget])) {
                $patchesPerPackage[$patchTarget] = array();
            }

            foreach ($packagePatches as $label => $data) {
                $isExtendedFormat = is_array($data) && is_numeric($label) && isset($data['label'], $data['url']);

                if ($isExtendedFormat) {
                    $label = $data['label'];
                    $url = (string)$data['url'];

                    if (isset($data['require']) && array_diff_key($data['require'], $this->packagesByName)) {
                        continue;
                    }
                } else {
                    $url = (string)$data;
                }

                if ($ownerPackage) {
                    $ownerPackageName = $ownerPackage->getName();

                    if (isset($excludedPatches[$ownerPackageName][$url])) {
                        continue;
                    }
                }

                if ($patchOwnerPath) {
                    $absolutePatchPath = $patchOwnerPath . '/' . $url;

                    if (strpos($absolutePatchPath, $vendorDir) === 0) {
                        $url = trim(substr($absolutePatchPath, strlen($vendorDir)), '/');
                    }
                }

                $patchesPerPackage[$patchTarget][$url] = $label;
            }
        }

        return array_filter($patchesPerPackage);
    }

    protected function getAllPatches()
    {
        $repositoryManager = $this->composer->getRepositoryManager();

        $localRepository = $repositoryManager->getLocalRepository();
        $packages = $localRepository->getPackages();

        $allPatchesFromPackages = $this->collectPatchesFromPackages();

        foreach ($packages as $package) {
            $extra = $package->getExtra();

            if (!isset($extra['patches'])) {
                continue;
            }

            $patches = isset($extra['patches']) ? $extra['patches'] : array();
            $patches = $this->preparePatchDefinitions($patches, $package);

            $this->installedPatches[$package->getName()] = $patches;

            foreach ($patches as $targetPackage => $packagePatches) {
                if (!isset($allPatchesFromPackages[$targetPackage])) {
                    $allPatchesFromPackages[$targetPackage] = array();
                }

                $allPatchesFromPackages[$targetPackage] = array_merge($packagePatches, $allPatchesFromPackages[$targetPackage]);
            }
        }

        return $allPatchesFromPackages;
    }

    public function getExcludedPatches()
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (!$this->excludedPatches) {
            $this->excludedPatches = array();

            if (isset($extra['excluded-patches'])) {
                foreach ($extra['excluded-patches'] as $patchOwner => $patches) {
                    if (!isset($this->excludedPatches[$patchOwner])) {
                        $this->excludedPatches[$patchOwner] = array();
                    }

                    $this->excludedPatches[$patchOwner] = array_flip($patches);
                }
            }
        }

        return $this->excludedPatches;
    }

    public function collectPatchesFromPackages()
    {
        // First, try to get the patches from the root composer.json.
        $extra = $this->composer->getPackage()->getExtra();
        if (isset($extra['patches'])) {
            $this->io->write('<info>Gathering patches for root package.</info>');
            $patches = $extra['patches'];

            return $this->preparePatchDefinitions($patches);
        } elseif (isset($extra['patches-file'])) {
            $this->io->write('<info>Gathering patches from patch file.</info>');

            $patches = file_get_contents($extra['patches-file']);
            $patches = json_decode($patches, true);
            $error = json_last_error();

            if ($error != 0) {
                switch ($error) {
                    case JSON_ERROR_DEPTH:
                        $msg = ' - Maximum stack depth exceeded';
                        break;
                    case JSON_ERROR_STATE_MISMATCH:
                        $msg =  ' - Underflow or the modes mismatch';
                        break;
                    case JSON_ERROR_CTRL_CHAR:
                        $msg = ' - Unexpected control character found';
                        break;
                    case JSON_ERROR_SYNTAX:
                        $msg =  ' - Syntax error, malformed JSON';
                        break;
                    case JSON_ERROR_UTF8:
                        $msg =  ' - Malformed UTF-8 characters, possibly incorrectly encoded';
                        break;
                    default:
                        $msg =  ' - Unknown error';
                        break;
                }
                throw new \Exception('There was an error in the supplied patches file:' . $msg);
            }

            if (isset($patches['patches'])) {
                $patches = $patches['patches'];

                return $this->preparePatchDefinitions($patches);
            } elseif (!$patches) {
                throw new \Exception('There was an error in the supplied patch file');
            }
        }

        return array();
    }

    public function removePatches(\Composer\Installer\PackageEvent $event)
    {
        $operations = $event->getOperations();

        foreach ($operations as $operation) {
            if (!$operation instanceof \Composer\DependencyResolver\Operation\UninstallOperation) {
                continue;
            }

            $package = $operation->getPackage();
            $extra = $package->getExtra();

            if (isset($extra['patches'])) {
                $patches = $this->preparePatchDefinitions($extra['patches'], $package);

                foreach ($patches as $targetPackageName => $packagePatches) {
                    $this->packagesToReinstall[] = $targetPackageName;
                }
            }
        }
    }

    public function postInstall(\Composer\Script\Event $event)
    {
        $installationManager = $this->composer->getInstallationManager();
        $packageRepository = $this->composer->getRepositoryManager()->getLocalRepository();

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $manager = $event->getComposer()->getInstallationManager();

        $packagesUpdated = false;

        if ($this->isPatchingEnabled()) {
            $allPatches = $this->getAllPatches();
        } else {
            $allPatches = array();
        }

        $forceReinstall = getenv('COMPOSER_FORCE_REPATCH');

        /**
         * Uninstall packages that are targeted by patches that have changed
         */
        foreach ($packageRepository->getPackages() as $package) {
            $packageName = $package->getName();

            if (isset($allPatches[$packageName])) {
                $patches = $allPatches[$packageName];
            } else {
                $patches = array();
            }

            $extra = $package->getExtra();

            if (!isset($extra['patches_applied'])) {
                continue;
            }

            if (isset($extra['patches_applied']) && !$forceReinstall) {
                $applied = $extra['patches_applied'];

                if (!$applied) {
                    continue;
                }

                foreach ($patches as $url => &$description) {
                    $absolutePatchPath = $vendorDir . '/' . $url;

                    if (file_exists($absolutePatchPath)) {
                        $url = $absolutePatchPath;
                    }

                    $description = $description . ', md5:' . md5_file($url);
                }

                if (!array_diff_assoc($applied, $patches) && !array_diff_assoc($patches, $applied)) {
                    continue;
                }
            }

            $this->packagesToReinstall[] = $package->getName();
        }

        if ($this->packagesToReinstall) {
            $this->io->write('<info>Re-installing packages that were targeted by patches.</info>');

            foreach (array_unique($this->packagesToReinstall) as $packageName) {
                $package = $packageRepository->findPackage($packageName, '*');

                $uninstallOperation = new \Composer\DependencyResolver\Operation\InstallOperation(
                    $package,
                    'Re-installing package.'
                );

                $installationManager->install($packageRepository, $uninstallOperation);

                $extra = $package->getExtra();

                unset($extra['patches_applied']);

                $packagesUpdated = true;
                $package->setExtra($extra);
            }
        }

        /**
         * Apply patches
         */
        foreach ($packageRepository->getPackages() as $package) {
            $packageName = $package->getName();

            if (!isset($allPatches[$packageName])) {
                if ($this->io->isVerbose()) {
                    $this->io->write('<info>No patches found for ' . $packageName . '.</info>');
                }

                continue;
            }

            $patches = $allPatches[$packageName];
            $extra = $package->getExtra();

            foreach ($patches as $url => &$description) {
                $absolutePatchPath = $vendorDir . '/' . $url;

                if (file_exists($absolutePatchPath)) {
                    $url = $absolutePatchPath;
                }

                $description = $description . ', md5:' . md5_file($url);
            }

            if (isset($extra['patches_applied'])) {
                $applied = $extra['patches_applied'];

                if (!array_diff_assoc($applied, $patches) && !array_diff_assoc($patches, $applied)) {
                    continue;
                }
            }

            $patches = $allPatches[$packageName];

            $this->io->write('  - Applying patches for <info>' . $packageName . '</info>');

            $installPath = $manager->getInstaller($package->getType())->getInstallPath($package);

            $downloader = new \Composer\Util\RemoteFilesystem($this->io, $this->composer->getConfig());

            // Track applied patches in the package info in installed.json
            $extra['patches_applied'] = array();

            $allPackagePatchesApplied = true;
            foreach ($patches as $url => $description) {
                $urlLabel = '<info>' . $url . '</info>';
                $absolutePatchPath = $vendorDir . '/' . $url;

                if (file_exists($absolutePatchPath)) {
                    $ownerName  = implode('/', array_slice(explode('/', $url), 0, 2));

                    $urlLabel = '<info>' . $ownerName . ': ' . trim(substr($url, strlen($ownerName)), '/') . '</info>';

                    $url = $absolutePatchPath;
                }

                $this->io->write('    ~ ' . $urlLabel);
                $this->io->write('      ' . '<comment>' . $description. '</comment>');

                try {
                    $this->eventDispatcher->dispatch(NULL, new PatchEvent(PatchEvents::PRE_PATCH_APPLY, $package, $url, $description));

                    $this->getAndApplyPatch($downloader, $installPath, $url);

                    $this->eventDispatcher->dispatch(NULL, new PatchEvent(PatchEvents::POST_PATCH_APPLY, $package, $url, $description));

                    $appliedPatchPath = $url;

                    if (strpos($appliedPatchPath, $vendorDir) === 0) {
                        $appliedPatchPath = trim(substr($appliedPatchPath, strlen($vendorDir)), '/');
                    }

                    $extra['patches_applied'][$appliedPatchPath] = $description . ', md5:' . md5_file($url);
                } catch (\Exception $e) {
                    $this->io->write('   <error>Could not apply patch! Skipping.</error>');

                    $allPackagePatchesApplied = false;

                    if ($this->io->isVerbose()) {
                        $this->io->write('<warning>' . trim($e->getMessage(), "\n ") . '</warning>');
                    }

                    if (getenv('COMPOSER_EXIT_ON_PATCH_FAILURE')) {
                        throw new \Exception(sprintf('Cannot apply patch %s (%s)!', $description, $url));
                    }
                }
            }

            if ($allPackagePatchesApplied) {
                $packagesUpdated = true;
                ksort($extra);
                $package->setExtra($extra);
            }

            $this->io->write('');
            $this->writePatchReport($patches, $installPath);
        }

        if ($packagesUpdated) {
            $packageRepository->write();
        }
    }

    protected function getPackageFromOperation(\Composer\DependencyResolver\Operation\OperationInterface $operation)
    {
        if ($operation instanceof \Composer\DependencyResolver\Operation\InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof \Composer\DependencyResolver\Operation\UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            throw new \Exception(sprintf('Unknown operation: %s', get_class($operation)));
        }

        return $package;
    }

    protected function getAndApplyPatch(\Composer\Util\RemoteFilesystem $downloader, $installPatch, $patchSource)
    {
        if (file_exists($patchSource)) {
            $filename = realpath($patchSource);
        } else {
            $filename = uniqid('/tmp/') . '.patch';

            // Download file from remote filesystem to this location.
            $hostname = parse_url($patchSource, PHP_URL_HOST);
            $downloader->copy($hostname, $patchSource, $filename, false);
        }

        // Modified from drush6:make.project.inc
        $patchAppliedSuccessfully = false;

        // The order here is intentional. p1 is most likely to apply with git apply.
        // p0 is next likely. p2 is extremely unlikely, but for some special cases,
        // it might be useful.
        $patchLevels = array('-p1', '-p0', '-p2');

        foreach ($patchLevels as $patchLevel) {
            $patchCheckedSuccessfully = $this->executeCommand('cd %s && GIT_DIR=. git apply --check %s %s', $installPatch, $patchLevel, $filename);

            if ($patchCheckedSuccessfully) {
                // Apply the first successful style.
                $patchAppliedSuccessfully = $this->executeCommand('cd %s && GIT_DIR=. git apply %s %s', $installPatch, $patchLevel, $filename);
                break;
            }
        }

        // In some rare cases, git will fail to apply a patch, fallback to using
        // the 'patch' command.
        if (!$patchAppliedSuccessfully) {
            foreach ($patchLevels as $patchLevel) {
                // --no-backup-if-mismatch here is a hack that fixes some
                // differences between how patch works on windows and unix.
                if ($patchAppliedSuccessfully = $this->executeCommand('patch %s --no-backup-if-mismatch -d %s < %s', $patchLevel, $installPatch, $filename)) {
                    break;
                }
            }
        }

        if (isset($hostname)) {
            unlink($filename);
        }

        if (!$patchAppliedSuccessfully) {
            throw new \Exception(sprintf('Cannot apply patch %s', $patchSource));
        }
    }

    /**
     * Enabled by default if there are project packages that include patches, but root package can still
     * explicitly disable them.
     *
     * @return bool
     */
    protected function isPatchingEnabled()
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (empty($extra['patches'])) {
            return isset($extra['enable-patching']) ? $extra['enable-patching'] : false;
        } else {
            return isset($extra['enable-patching']) && !$extra['enable-patching'] ? false : true;
        }
    }

    protected function writePatchReport($patches, $directory) {
        $outputLines = array();
        $outputLines[] = 'This file was automatically generated by Composer Patches';
        $outputLines[] = 'Patches applied to this directory:';
        $outputLines[] = '';

        foreach ($patches as $source => $description) {
            $outputLines[] = $description;
            $outputLines[] = 'Source: ' . $source;
            $outputLines[] = '';
            $outputLines[] = '';
        }

        file_put_contents($directory . '/PATCHES.txt', implode("\n", $outputLines));
    }

    protected function executeCommand()
    {
        $arguments = func_get_args();

        foreach ($arguments as $index => $arg) {
            if ($index == 0) {
                continue;
            }

            $arguments[$index] = escapeshellarg($arg);
        }

        $command = call_user_func_array('sprintf', $arguments);

        $outputGenerator = '';

        if ($this->io->isVerbose()) {
            $this->io->write('<comment>' . $command . '</comment>');
            $io = $this->io;

            $outputGenerator = function ($type, $data) use ($io) {
                if ($type == \Symfony\Component\Process\Process::ERR) {
                    $io->write('<error>' . $data . '</error>');
                } else {
                    $io->write('<comment>' . $data . '</comment>');
                }
            };
        }

        return $this->executor->execute($command, $outputGenerator) == 0;
    }
}
