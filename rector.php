<?php

declare(strict_types=1);

// use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Aws_lib.php',
        __DIR__ . '/Aws_util.php'
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php83: true)
    /*->withRules([
        CompleteDynamicPropertiesRector::class,
    ])*/
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
