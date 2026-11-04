<?php

/**
 * Signage Preview Field
 *
 * Embeds the rendered player in an iframe so screens, channels and slides
 * can be previewed in the Panel without pairing and approving a device.
 */

return [
    'computed' => [
        'src' => function () {
            $model = $this->model();
            $template = $model->intendedTemplate()->name();

            $params = ['preview' => 1];

            if ($template === 'screen') {
                $host = $model;
            } else {
                // channels/slides are rendered through the first available screen
                $screens = kirby()->page('signage/screens');
                $host = $screens ? ($screens->childrenAndDrafts()->first()) : null;

                if (!$host) {
                    return null;
                }

                $params[$template] = $model->slug();
            }

            return $host->url() . '?' . http_build_query($params);
        },
        'orientation' => function () {
            $model = $this->model();

            return $model->intendedTemplate()->name() === 'screen'
                ? (string) $model->orientation()->or('horizontal')
                : 'horizontal';
        },
        'selectable' => function () {
            return $this->model()->intendedTemplate()->name() !== 'screen';
        },
    ],
];
