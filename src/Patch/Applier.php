<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch;

use Vaimo\ComposerPatches\Config as PluginConfig;

class Applier
{
    /**
     * @var \Vaimo\ComposerPatches\Logger
     */
    private $logger;
    
    /**
     * @var \Vaimo\ComposerPatches\Shell
     */
    private $shell;

    /**
     * @var array
     */
    private $config;

    /**
     * @var \Vaimo\ComposerPatches\Utils\ConfigUtils
     */
    private $applierUtils;

    /**
     * @var \Vaimo\ComposerPatches\Utils\TemplateUtils
     */
    private $templateUtils;
    
    /**
     * @param \Vaimo\ComposerPatches\Logger $logger
     * @param array $config
     */
    public function __construct(
        \Vaimo\ComposerPatches\Logger $logger,
        array $config
    ) {
        $this->logger = $logger;
        $this->config = $config;

        $this->shell = new \Vaimo\ComposerPatches\Shell($logger);
        $this->applierUtils = new \Vaimo\ComposerPatches\Utils\ConfigUtils();
        $this->templateUtils = new \Vaimo\ComposerPatches\Utils\TemplateUtils();
    }

    public function applyFile($filename, $cwd, array $config = array())
    {
        $result = false;
        
        list($type, $patchLevel, $operationName) = array_fill(0, 3, 'UNKNOWN');

        $applierConfig = $this->applierUtils->mergeApplierConfig(
            $this->config, 
            array_filter($config)
        );

        $applierConfig = $this->applierUtils->sortApplierConfig($applierConfig);
        
        $patchers = isset($applierConfig[PluginConfig::PATCHER_APPLIERS ]) 
            ? array_filter($applierConfig[PluginConfig::PATCHER_APPLIERS ]) 
            : array();
        
        $operations = isset($applierConfig[PluginConfig::PATCHER_OPERATIONS]) 
            ? array_filter($applierConfig[PluginConfig::PATCHER_OPERATIONS]) 
            : array();
        
        $levels = isset($applierConfig[PluginConfig::PATCHER_LEVELS]) 
            ? $applierConfig[PluginConfig::PATCHER_LEVELS] 
            : array();

        $patcherSequence = $applierConfig[PluginConfig::PATCHER_SEQUENCE][PluginConfig::PATCHER_APPLIERS];

        if (!$patchers) {
            $this->logger->writeVerbose(
                'error',
                sprintf(
                    'No valid patchers found with sequence: %s', 
                    implode(',', $patcherSequence)
                )
            );
        }
        
        $patters = array(
            '{{%s}}' => 'escapeshellarg',
            '[[%s]]' => false
        );

        $resultCache = array();
        
        foreach ($levels as $sequenceIndex => $patchLevel) {
            foreach ($patchers as $type => $patcher) {
                $result = true;
                
                $operationResults[$type] = array_fill_keys(array_keys($operations), '');
                
                foreach ($operations as $operationCode => $operationName) {
                    if (!isset($patcher[$operationCode])) {
                        continue;
                    }
                    
                    $arguments = array_replace($operationResults[$type], array(
                        PluginConfig::PATCHER_ARG_LEVEL => $patchLevel,
                        PluginConfig::PATCHER_ARG_FILE => $filename,
                        PluginConfig::PATCHER_ARG_CWD => $cwd
                    ));

                    $applierOperations = is_array($patcher[$operationCode]) 
                        ? $patcher[$operationCode] 
                        : array($patcher[$operationCode]);
                    
                    foreach ($applierOperations as $applierOperation) {
                        $passOnFailure = substr($applierOperation, 0, 1) === '!';
                        $applierOperation = ltrim($applierOperation, '!');
                        
                        $command = $this->templateUtils->compose($applierOperation, $arguments, $patters);

                        $resultKey = $cwd . '|' . $command;

                        if ($passOnFailure) {
                            $this->logger->writeVerbose(
                                \Vaimo\ComposerPatches\Logger::TYPE_NONE, 
                                '<comment>***</comment> The expected result to execution is a failure <comment>***</comment>'
                            );
                        }

                        if (!isset($resultCache[$command])) {
                            $resultCache[$resultKey] = $this->shell->execute($command, $cwd);
                        }

                        list($result, $output) = $resultCache[$resultKey];
                        
                        if ($passOnFailure) {
                            $result = !$result;
                        }
                        
                        if (!$result) {
                            continue;
                        }

                        $operationResults[$type][$operationCode] = $output;
                        
                        break;
                    }

                    if (!$result) {
                        break;
                    }
                }

                if ($result) {
                    break 2;
                }
                
                $this->logger->writeVerbose(
                    'warning',
                    '%s (type=%s) failed with p=%s',
                    array($operationName, $type, $patchLevel)
                );
            }
        }

        if ($result) {
            $this->logger->writeVerbose(
                'info', 
                'SUCCESS with type=%s (p=%s)', 
                array($type, $patchLevel)
            );
        }

        if (!$result) {
            throw new \Exception(
                sprintf('Cannot apply patch %s', $filename)
            );
        }
    }
}
