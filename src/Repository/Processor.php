<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Repository;

use Composer\Repository\WritableRepositoryInterface as PackageRepository;
use Vaimo\ComposerPatches\Repository\PatchesApplier as Applier;
use Vaimo\ComposerPatches\Patch\DefinitionList\Loader;

class Processor
{
    /**
     * @var \Vaimo\ComposerPatches\Logger
     */
    private $logger;

    /**
     * @var \Vaimo\ComposerPatches\Utils\PatchListUtils
     */
    private $patchListUtils;

    /**
     * @param \Vaimo\ComposerPatches\Logger $logger
     */
    public function __construct(
        \Vaimo\ComposerPatches\Logger $logger
    ) {
        $this->logger = $logger;

        $this->patchListUtils = new \Vaimo\ComposerPatches\Utils\PatchListUtils();
    }
    
    public function process(PackageRepository $repository, Loader $loader, Applier $applier)
    {
        $this->logger->write('info', 'Processing patches configuration');
        
        $patches = $loader->loadFromPackagesRepository($repository);

        $loggerIndentation = $this->logger->push('-');
            
        try {
            $packagesUpdated = $applier->apply($repository, $patches);
        } catch (\Vaimo\ComposerPatches\Exceptions\PatchFailureException $exception) {
            $this->logger->reset($loggerIndentation);
            
            $this->patchListUtils->sanitizeFileSystem($patches);
            
            $repository->write();
            
            throw $exception;
        }

        $this->logger->reset($loggerIndentation);
        
        if (!$packagesUpdated) {
            $this->logger->writeRaw('Nothing to patch');
        } else {
            $this->logger->write('info', 'Writing patch info to install file');
        }
        
        $this->patchListUtils->sanitizeFileSystem($patches);
        $repository->write();
    }
}
