<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches;

use Vaimo\ComposerPatches\Composer\ConfigKeys as Config;
use Vaimo\ComposerPatches\Config as PluginConfig;

class Bootstrap
{
    /**
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * @var \Vaimo\ComposerPatches\Factories\ConfigFactory
     */
    private $configFactory;

    /**
     * @var \Vaimo\ComposerPatches\Factories\PatchesLoaderFactory
     */
    private $loaderFactory;

    /**
     * @var \Vaimo\ComposerPatches\Factories\PatchesApplierFactory
     */
    private $applierFactory;

    /**
     * @var \Vaimo\ComposerPatches\Repository\Processor
     */
    private $repositoryProcessor;

    /**
     * @var \Vaimo\ComposerPatches\Patch\DefinitionList\Loader\ComponentPool
     */
    private $loaderComponents;

    /**
     * @var \Vaimo\ComposerPatches\Interfaces\ListResolverInterface
     */
    private $listResolver;
    
    /**
     * @var \Vaimo\ComposerPatches\Managers\LockerManager
     */
    private $lockerManager;

    /**
     * @var \Vaimo\ComposerPatches\Utils\DataUtils
     */
    private $dataUtils;

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     * @param \Vaimo\ComposerPatches\Interfaces\ListResolverInterface $listResolver
     * @param \Vaimo\ComposerPatches\Factories\ConfigFactory $configFactory
     * @param bool $explicitMode
     */
    public function __construct(
        \Composer\Composer $composer,
        \Composer\IO\IOInterface $io,
        \Vaimo\ComposerPatches\Factories\ConfigFactory $configFactory,
        \Vaimo\ComposerPatches\Interfaces\ListResolverInterface $listResolver,
        $explicitMode = false
    ) {
        $this->composer = $composer;
        $this->configFactory = $configFactory;

        $logger = new \Vaimo\ComposerPatches\Logger($io, !$explicitMode);

        $this->listResolver = $listResolver;
        
        $this->loaderComponents = new \Vaimo\ComposerPatches\Patch\DefinitionList\Loader\ComponentPool(
            $composer,
            $io
        );

        $this->loaderFactory = new \Vaimo\ComposerPatches\Factories\PatchesLoaderFactory($composer);

        $this->applierFactory = new \Vaimo\ComposerPatches\Factories\PatchesApplierFactory(
            $composer,
            $logger
        );
        
        $this->repositoryProcessor = new \Vaimo\ComposerPatches\Repository\Processor($logger);

        $this->lockerManager = new \Vaimo\ComposerPatches\Managers\LockerManager($io);
        
        $this->dataUtils = new \Vaimo\ComposerPatches\Utils\DataUtils();
    }

    public function applyPatches($devMode = false)
    {
        $this->applyPatchesWithConfig(
            $this->configFactory->create(),
            $devMode
        );
    }

    public function stripPatches($devMode = false)
    {
        $configSources = array(
            array(\Vaimo\ComposerPatches\Config::PATCHER_SOURCES => false)
        );
        
        $this->applyPatchesWithConfig(
            $this->configFactory->create($configSources),
            $devMode
        );
    }

    private function applyPatchesWithConfig(\Vaimo\ComposerPatches\Config $config, $devMode = false)
    {
        $repository = $this->composer->getRepositoryManager()->getLocalRepository();

        $patchesLoader = $this->loaderFactory->create($this->loaderComponents, $config, $devMode);
        $patchesApplier = $this->applierFactory->create($config, $this->listResolver);

        $this->repositoryProcessor->process($repository, $patchesLoader, $patchesApplier);
    }
    
    public function sanitizeLocker()
    {
        if (!$lock = $this->lockerManager->readLockData()) {
            return;
        }

        $lockBefore = serialize($lock);
        
        $nodes = $this->dataUtils->getNodeReferencesByPaths($lock, array(
            implode('/', array(Config::PACKAGES, '*', Config::CONFIG_ROOT)),
            implode('/', array(Config::PACKAGES_DEV, '*', Config::CONFIG_ROOT))
        ));

        foreach ($nodes as &$node) {
            unset($node[PluginConfig::APPLIED_FLAG]);
            unset($node);
        }
        
        if (serialize($lock) === $lockBefore) {
            return;
        }
        
        $this->lockerManager->writeLockData($lock);
    }
}
