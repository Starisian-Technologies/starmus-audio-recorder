<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withPaths(
		array(
			__DIR__ . '/src',
		)
	)
	->withSkip(
		array(
			'*/vendor/*',
			'*/tests/*',
			'*/node_modules/*',
			'*/wordpress-installer/*',
			'*/wp-admin/*',
			'*/wp-content/*',
		)
	)
	// Use the modern "Prepared Sets" (Replaces the old LevelSetList/SetList)
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
		codingStyle: true,
		typeDeclarations: true,
		// Keep false for WP. Prevents removing 'public' from hook callbacks.
		privatization: false, 
		earlyReturn: true,
		// CAUTION: Set to false if you want to keep empty() checks. 
		// Set to true if you want strict comparisons (===).
		strictBooleans: false, 
	)
	// Targets PHP 8.2 features
	->withPhpSets( php82: true );
