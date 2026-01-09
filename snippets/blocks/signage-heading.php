<?php
/**
 * Signage Heading Block
 * Renders heading content for digital signage slides
 */

$level = $block->level()->or('h2');
?>
<<?= $level ?> class="signage-block signage-heading">
    <?= $block->text() ?>
</<?= $level ?>>
