<?php
/**
 * RECTOR vs PHPCBF BRACE CONFLICT FIX
 * 
 * Problem: Your template mixes brace styles:
 * - Line 19: <?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) { ?>
 * - Line 22: <?php } ?>  (PHPCBF wants this)
 * - Line 60: if ( $mp3_url ) { ?> (Rector wants this)
 * 
 * Solution: Configure PHPCBF to ignore template brace styles
 */

// Add to phpcs.xml.dist in the WordPress-Core rule:
?>
<rule ref="WordPress-Core">
  <exclude name="WordPress.Files.FileName"/>
  <exclude name="WordPress.NamingConventions.ValidVariableName"/>
  <!-- FIX: Skip brace style checks in templates -->
  <exclude name="Squiz.ControlStructures.ControlSignature"/>
  <exclude name="PSR2.ControlStructures.ControlStructureSpacing"/>
</rule>

<?php
/**
 * OR: Add template-specific exclusions
 */
?>
<!-- Skip brace checks in template files -->
<rule ref="WordPress-Core">
  <exclude-pattern>*/templates/*</exclude-pattern>
</rule>

<?php
/**
 * MINIMAL TEMPLATE FIX: Make all braces consistent
 * Replace inconsistent patterns in your template:
 */

// BEFORE (inconsistent):
// <?php if ( $condition ) { ?>
// <?php } ?>

// AFTER (consistent):
// <?php if ( $condition ) : ?>
// <?php endif; ?>

/**
 * Or use this sed command to fix all templates:
 */
// find src/templates -name "*.php" -exec sed -i 's/<?php } ?>/<?php endif; ?>/g' {} \;
// find src/templates -name "*.php" -exec sed -i 's/) { ?>/) : ?>/g' {} \;