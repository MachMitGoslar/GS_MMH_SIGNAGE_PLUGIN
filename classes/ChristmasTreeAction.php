<?php

use Kirby\Toolkit\Str;
use tobimori\DreamForm\Actions\Action;

/**
 * DreamForm action "Weihnachtsbaum: zur Freigabe einreichen"
 *
 * Creates a greeting as a DRAFT child page of a christmas tree slide. Nothing
 * reaches the screen until the team publishes ("Am Baum") the greeting in the
 * panel – that is the moderation step.
 *
 * Only loaded when DreamForm is installed (see index.php).
 */
class ChristmasTreeAction extends Action
{
    public static function blueprint(): array
    {
        return [
            'name' => 'Weihnachtsbaum: Gruß zur Freigabe',
            'preview' => 'fields',
            'wysiwyg' => true,
            'icon' => 'heart',
            'tabs' => [
                'settings' => [
                    'label' => 'Einstellungen',
                    'fields' => [
                        'treeSlide' => [
                            'label' => 'Weihnachtsbaum-Folie',
                            'type' => 'pages',
                            'multiple' => false,
                            'required' => true,
                            'query' => "site.find('" . SlideLibrary::POOL_ID . "').childrenAndDrafts"
                                . ".filterBy('slide_type', '" . ChristmasTree::SLIDE_TYPE . "')",
                            'help' => 'Grüße werden hier als Entwurf angelegt und erscheinen erst nach '
                                . 'der Freigabe am Baum.',
                        ],
                        'nameField' => [
                            'label' => 'Feld für den Namen',
                            'extends' => 'dreamform/fields/field',
                            'width' => '1/2',
                            'help' => 'Leer = Feld mit dem Schlüssel „name“',
                        ],
                        'textField' => [
                            'label' => 'Feld für den Gruß',
                            'extends' => 'dreamform/fields/field',
                            'width' => '1/2',
                            'help' => 'Leer = Feld mit dem Schlüssel „gruss“',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function type(): string
    {
        return 'christmas-tree';
    }

    public function supportsQueues(): bool
    {
        return false;
    }

    public function run(): void
    {
        $slide = $this->block()->treeSlide()->toPage();

        if (!ChristmasTree::isTree($slide)) {
            $this->cancel('Für den Weihnachtsbaum ist keine gültige Folie eingestellt.', public: false);

            return;
        }

        $values = ChristmasTree::sanitize(
            $this->fieldValue('nameField', 'name'),
            $this->fieldValue('textField', 'gruss'),
        );

        if (is_string($values)) {
            $this->cancel($values, public: true);

            return;
        }

        $submission = $this->submission();
        $greeting = $this->kirby()->impersonate('kirby', fn () => $slide->createChild([
            'slug' => Str::slug('gruss-' . $values['name']) . '-' . Str::lower(Str::random(6, 'alphaNum')),
            'template' => ChristmasTree::GREETING_TEMPLATE,
            'draft' => true,
            'content' => [
                'title' => 'Gruß von ' . $values['name'],
                'greeting_name' => $values['name'],
                'greeting_text' => $values['text'],
                'submitted_at' => date('Y-m-d H:i:s'),
                'submission_ref' => $submission->uuid()?->toString() ?? $submission->id(),
            ],
        ]));

        $this->log([
            'text' => 'Gruß von „' . $values['name'] . '“ wartet auf Freigabe. '
                . 'Freigeben im Panel unter Signage → Folien → ' . $slide->title()->value()
                . ' (' . $greeting->panel()->url(true) . ').',
        ], type: 'info', icon: 'heart', title: 'Weihnachtsbaum');
    }

    private function fieldValue(string $setting, string $fallbackKey): ?string
    {
        $fieldId = $this->block()->{$setting}()->value();
        $value = $fieldId
            ? $this->submission()->valueForId($fieldId)
            : $this->submission()->valueFor($fallbackKey);

        return $value?->value();
    }

    private function kirby(): \Kirby\Cms\App
    {
        return \Kirby\Cms\App::instance();
    }
}
