<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
	// Set the working directory paths
	->withPaths(
		array(
			__DIR__ . '/src',
		)
	)

	// Target PHP 8.2 for language features and compatibility
	->withPhpVersion( PhpVersion::PHP_82 )

	// Enable PHP 8.2 features
	->withPhpSets( php82: true )

	// Use the modern "Prepared Sets"
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
		codingStyle: true,
		typeDeclarations: true,
		// Keep false for WP. Prevents removing 'public' from hook callbacks.
		privatization: false,
		earlyReturn: true,
		instanceOf: true,
		// CAUTION: Set to false if you want to keep empty() checks.
		// Set to true if you want strict comparisons (===).
		strictBooleans: false
	)

	// Skip paths and specific rules incompatible with WordPress VIP standards
	->withSkip(
		array(
			'*/vendor/*',
			'*/tests/*',
			'*/node_modules/*',
			'*/wordpress-installer/*',
			'*/wp-admin/*',
			'*/wp-content/*',
			// VIP Standards: Skip incompatible transformations
			\Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class,
			\Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
			\Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector::class,
			// African Performance: Keep explicit error handling
			\Rector\DeadCode\Rector\If_\RemoveDeadInstanceOfRector::class,
			\Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector::class,
		)
	);
