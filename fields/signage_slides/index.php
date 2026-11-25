<?php

/**
 * Signage Slides Field
 *
 * The channel's slide list: a structure field with one row per slide and the
 * display duration this channel gives it. Older channels store a plain list
 * of page references; those are shown as rows here (duration empty), so the
 * channel keeps its slides and is stored as rows on the next save.
 */

return [
    'extends' => 'structure',
    'props' => [
        'value' => function ($value = null) {
            return SlideLibrary::rows($value);
        },
    ],
];
