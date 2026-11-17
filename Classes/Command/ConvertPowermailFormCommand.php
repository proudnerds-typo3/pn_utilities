<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Converts a legacy Powermail form (stored in tx_powermail_domain_model_form /
 * _page / _field) into a TYPO3 ext:form "*.form.yaml" definition.
 *
 * Powermail's PHP extension no longer needs to be installed — the command reads
 * the raw database tables directly via Doctrine, so it works purely as a
 * data-migration helper after the extension has been removed.
 *
 * Usage:
 *   ddev typo3 pn:powermail:convert            # list all available forms
 *   ddev typo3 pn:powermail:convert 2          # convert form with uid 2
 *   ddev typo3 pn:powermail:convert --all      # convert every non-deleted form
 *   ddev typo3 pn:powermail:convert 2 --force  # overwrite an existing file
 *   ddev typo3 pn:powermail:convert 2 --path=EXT:my_sitepackage/Resources/Private/Forms/
 */
#[AsCommand(
    name: 'pn:powermail:convert',
    description: 'Convert a legacy Powermail form (DB tables) into an ext:form *.form.yaml',
)]
final class ConvertPowermailFormCommand extends Command
{
    /**
     * Must be listed in the site package's persistenceManager.allowedExtensionPaths,
     * or the backend form manager does not show the generated forms.
     */
    private const DEFAULT_FORMS_PATH = 'EXT:base_sitepackage/Resources/Private/Forms/';

    /**
     * Maps the Powermail field "type" column to an ext:form element type.
     * Types handled specially (input, select, …) are resolved in code.
     */
    private const TYPE_MAP = [
        'input' => 'Text',
        'textarea' => 'Textarea',
        'select' => 'SingleSelect',
        'check' => 'MultiCheckbox',
        'radio' => 'RadioButton',
        'file' => 'FileUpload',
        'date' => 'Date',
        'hidden' => 'Hidden',
        'password' => 'Password',
        'html' => 'StaticText',
        'content' => 'ContentElement',
        'location' => 'Text',
        'country' => 'SingleSelect',
        'typoscript' => 'StaticText',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormTools $flexFormTools,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('formUid', InputArgument::OPTIONAL, 'UID of the Powermail form to convert')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Convert all non-deleted forms')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the target file if it already exists')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the resulting YAML instead of writing a file')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Target folder for the *.form.yaml files', self::DEFAULT_FORMS_PATH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool)$input->getOption('force');
        $dryRun = (bool)$input->getOption('dry-run');
        $formsPath = rtrim((string)$input->getOption('path'), '/') . '/';

        $forms = $this->fetchForms();
        if ($forms === []) {
            $io->warning('No Powermail forms found (tx_powermail_domain_model_form is empty or the table is missing).');
            return Command::SUCCESS;
        }

        if (!$input->getOption('all') && $input->getArgument('formUid') === null) {
            $io->title('Available Powermail forms');
            $rows = array_map(
                static fn(array $f): array => [$f['uid'], $f['title'], $f['pid']],
                $forms,
            );
            $io->table(['UID', 'Title', 'PID'], $rows);
            $io->writeln('Run again with a form UID, e.g. <info>pn:powermail:convert ' . $forms[0]['uid'] . '</info>, or <info>--all</info>.');
            return Command::SUCCESS;
        }

        if (!$input->getOption('all')) {
            $uid = (int)$input->getArgument('formUid');
            $forms = array_filter($forms, static fn(array $f): bool => (int)$f['uid'] === $uid);
            if ($forms === []) {
                $io->error(sprintf('Form with uid %d not found (or deleted).', $uid));
                return Command::FAILURE;
            }
        }

        $exitCode = Command::SUCCESS;
        foreach ($forms as $form) {
            if (!$this->convertForm($form, $io, $force, $dryRun, $formsPath)) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $form
     */
    private function convertForm(array $form, SymfonyStyle $io, bool $force, bool $dryRun, string $formsPath): bool
    {
        $io->section(sprintf('Form %d: %s', $form['uid'], $form['title']));

        $identifier = $this->slugify((string)$form['title']) ?: ('powermail-' . $form['uid']);
        $pages = $this->fetchPages((int)$form['uid']);

        if ($pages === []) {
            $io->warning('Form has no pages — nothing to convert.');
            return false;
        }

        $renderables = [];
        $pageIndex = 0;
        foreach ($pages as $page) {
            $fields = $this->fetchFields((int)$page['uid']);
            $elements = [];
            foreach ($fields as $field) {
                $element = $this->convertField($field, $io);
                if ($element !== null) {
                    $elements[] = $element;
                }
            }

            if ($elements === []) {
                continue;
            }

            $pageIndex++;
            $renderables[] = [
                'identifier' => 'page-' . $pageIndex,
                'label' => (string)$page['title'],
                'type' => 'Page',
                'renderables' => $elements,
            ];
        }

        if ($renderables === []) {
            $io->warning('No convertible fields found — skipping.');
            return false;
        }

        $definition = [
            'identifier' => $identifier,
            'type' => 'Form',
            'label' => (string)$form['title'],
            'prototypeName' => 'standard',
            'renderingOptions' => [
                'submitButtonLabel' => 'Submit',
            ],
            'finishers' => $this->buildFinishers((int)$form['uid'], (string)$form['title'], $io),
            'renderables' => $renderables,
            'variants' => [],
        ];

        $yaml = Yaml::dump($definition, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if ($dryRun) {
            $io->writeln($yaml);
            return true;
        }

        $dir = GeneralUtility::getFileAbsFileName($formsPath);
        if ($dir === '') {
            $io->error('Invalid target path (extension not loaded?): ' . $formsPath);
            return false;
        }
        if (!is_dir($dir)) {
            GeneralUtility::mkdir_deep($dir);
            if (!is_dir($dir)) {
                $io->error('Could not create target directory: ' . $dir);
                return false;
            }
        }

        $targetFile = $dir . $identifier . '.form.yaml';
        if (file_exists($targetFile) && !$force) {
            $io->error(sprintf('Target already exists: %s — use --force to overwrite.', $this->relativePath($targetFile)));
            return false;
        }

        GeneralUtility::writeFile($targetFile, $yaml);
        $io->success(sprintf('Wrote %s (%d page(s)).', $this->relativePath($targetFile), count($renderables)));

        return true;
    }

    /**
     * Build the ext:form finisher chain for a form, pulling what is available:
     *  - recipient e-mail / name / subject from the Powermail content-element
     *    FlexForm (settings.flexform.receiver.*), when a matching plugin exists;
     *  - sender address/name from the field-level sender_email / sender_name
     *    flags, expressed as {marker} placeholders that ext:form resolves at
     *    submit time;
     *  - an EmailToSender confirmation finisher when the submitter's e-mail
     *    field is known.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFinishers(int $formUid, string $title, SymfonyStyle $io): array
    {
        $flex = $this->fetchFlexFormConfig($formUid);
        $receiver = $flex['receiver'] ?? [];
        $senderFields = $this->fetchSenderFields($formUid);

        // Recipient(s): from FlexForm if present, otherwise a documented placeholder.
        $recipients = [];
        $receiverEmail = trim((string)($receiver['email'] ?? ''));
        if ($receiverEmail !== '') {
            $receiverName = trim((string)($receiver['name'] ?? '')) ?: 'Webmaster';
            foreach (preg_split('/[\r\n,;]+/', $receiverEmail) ?: [] as $addr) {
                $addr = trim($addr);
                if ($addr !== '') {
                    $recipients[$addr] = $receiverName;
                }
            }
        }
        if ($recipients === []) {
            $fallback = $this->defaultRecipientEmail();
            $io->note(sprintf('No recipient found in the content-element FlexForm — falling back to "%s". Set the real recipient in the generated YAML.', $fallback));
            $recipients = [$fallback => 'Webmaster'];
        }

        // Sender address/name as form-value placeholders, derived from the
        // Powermail field flags (sender_email / sender_name).
        $senderAddress = $senderFields['email'] !== null
            ? '{' . $senderFields['email'] . '}'
            : (trim((string)($receiver['senderEmail'] ?? '')) ?: 'no-reply@example.com');

        $senderName = $senderFields['names'] !== []
            ? implode(' ', array_map(static fn(string $m): string => '{' . $m . '}', $senderFields['names']))
            : (trim((string)($receiver['senderName'] ?? '')) ?: 'Website');

        $subject = trim((string)($receiver['subject'] ?? '')) ?: ('New submission: ' . $title);

        $finishers = [
            [
                'identifier' => 'EmailToReceiver',
                'options' => [
                    'subject' => $subject,
                    'recipients' => $recipients,
                    'senderAddress' => $senderAddress,
                    'senderName' => $senderName,
                    'format' => 'html',
                    'attachUploads' => true,
                ],
            ],
        ];

        // Confirmation e-mail to the submitter, when we know their address field.
        if ($senderFields['email'] !== null) {
            $finishers[] = [
                'identifier' => 'EmailToSender',
                'options' => [
                    'subject' => 'Confirmation of your message',
                    'recipients' => [
                        '{' . $senderFields['email'] . '}' => $senderName,
                    ],
                    'senderAddress' => trim((string)($receiver['senderEmail'] ?? '')) ?: 'no-reply@example.com',
                    'senderName' => trim((string)($receiver['senderName'] ?? '')) ?: 'Website',
                    'format' => 'html',
                ],
            ];
        }

        return $finishers;
    }

    /**
     * Fallback recipient when the FlexForm holds no receiver address: the
     * legacy project's powermailDevelopContextEmail if it still has one, then
     * MAIL.defaultMailFromAddress.
     */
    private function defaultRecipientEmail(): string
    {
        foreach ([
            $GLOBALS['TYPO3_CONF_VARS']['EXT']['powermailDevelopContextEmail'] ?? '',
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? '',
        ] as $email) {
            $email = trim((string)$email);
            if ($email !== '') {
                return $email;
            }
        }

        return 'no-reply@example.com';
    }

    /**
     * Find the Powermail plugin (tt_content.powermail_pi1) that references the
     * given form and return its parsed "settings.flexform" array, or null.
     *
     * @return array<array-key, mixed>|null
     */
    private function fetchFlexFormConfig(int $formUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('pi_flexform')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('CType', $qb->createNamedParameter('powermail_pi1')),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $xml = (string)($row['pi_flexform'] ?? '');
            if ($xml === '') {
                continue;
            }
            $parsed = $this->flexFormTools->convertFlexFormContentToArray($xml);
            $flexform = $parsed['settings']['flexform'] ?? null;
            if (!is_array($flexform)) {
                continue;
            }
            if ((int)($flexform['main']['form'] ?? 0) === $formUid) {
                return $flexform;
            }
        }

        return null;
    }

    /**
     * Read the Powermail sender-field flags for a form.
     *
     * @return array{email: string|null, names: array<int, string>}
     *         "email" = marker of the field flagged sender_email,
     *         "names" = markers of the fields flagged sender_name (in order).
     */
    private function fetchSenderFields(int $formUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_powermail_domain_model_field');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('f.marker', 'f.title', 'f.uid', 'f.sender_email', 'f.sender_name', 'f.sorting')
            ->from('tx_powermail_domain_model_field', 'f')
            ->join('f', 'tx_powermail_domain_model_page', 'p', $qb->expr()->eq('f.page', $qb->quoteIdentifier('p.uid')))
            ->where(
                $qb->expr()->eq('p.form', $qb->createNamedParameter($formUid, Connection::PARAM_INT)),
                $qb->expr()->eq('f.deleted', 0),
                $qb->expr()->in('f.sys_language_uid', [0, -1]),
                $qb->expr()->or(
                    $qb->expr()->eq('f.sender_email', 1),
                    $qb->expr()->eq('f.sender_name', 1),
                ),
            )
            ->orderBy('f.sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $email = null;
        $names = [];
        foreach ($rows as $row) {
            $marker = $this->fieldIdentifier($row);
            if ((int)$row['sender_email'] === 1 && $email === null) {
                $email = $marker;
            }
            if ((int)$row['sender_name'] === 1) {
                $names[] = $marker;
            }
        }

        return ['email' => $email, 'names' => $names];
    }

    /**
     * Convert a single Powermail field row to an ext:form renderable.
     * Returns null for field types that have no form-element equivalent
     * (submit, captcha, …).
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private function convertField(array $field, SymfonyStyle $io): ?array
    {
        $pmType = (string)$field['type'];

        if (in_array($pmType, ['submit', 'reset'], true)) {
            // ext:form renders its own submit button.
            return null;
        }

        if ($pmType === 'captcha') {
            $io->note(sprintf('Skipped field "%s" (type captcha — add a Honeypot/captcha element manually).', $field['title']));
            return null;
        }

        $formType = self::TYPE_MAP[$pmType] ?? 'Text';

        // input fields become a more specific type based on validation.
        if ($pmType === 'input') {
            $formType = match ((int)$field['validation']) {
                1 => 'Email',
                4 => 'Number',
                default => 'Text',
            };
        }
        if (($pmType === 'select' || $pmType === 'country') && (int)$field['multiselect'] === 1) {
            $formType = 'MultiSelect';
        }

        $identifier = $this->fieldIdentifier($field);

        $element = [
            'identifier' => $identifier,
            'label' => (string)$field['title'],
            'type' => $formType,
        ];

        // Static text / hidden elements
        if ($formType === 'StaticText') {
            $element['properties']['text'] = (string)$field['text'];
            return $element;
        }
        if ($formType === 'Hidden') {
            $element['defaultValue'] = (string)$field['prefill_value'];
            return $element;
        }
        if ($formType === 'ContentElement') {
            $element['properties']['contentElementUid'] = (int)$field['content_element'];
            return $element;
        }

        // FileUpload and multi-value elements take no scalar defaultValue.
        if (!in_array($formType, ['FileUpload', 'MultiCheckbox', 'MultiSelect'], true)) {
            $element['defaultValue'] = (string)$field['prefill_value'];
        }

        // Options for choice elements
        if (in_array($formType, ['SingleSelect', 'MultiSelect', 'MultiCheckbox', 'RadioButton'], true)) {
            $element['properties']['options'] = $this->parseOptions((string)$field['settings']);
        }

        // fluidAdditionalAttributes (placeholder, autocomplete, required)
        $attributes = [];
        $placeholder = trim((string)$field['placeholder']);
        if ($placeholder !== '') {
            $attributes['placeholder'] = $placeholder;
        }
        $autocomplete = $this->autocompleteToken($field);
        if ($autocomplete !== null) {
            $attributes['autocomplete'] = $autocomplete;
        }
        if ((int)$field['mandatory'] === 1) {
            $attributes['required'] = 'required';
        }
        if ($attributes !== []) {
            $element['properties']['fluidAdditionalAttributes'] = $attributes;
        }

        if (trim((string)$field['description']) !== '') {
            $element['properties']['elementDescription'] = trim((string)$field['description']);
        }

        // Validators
        $validators = [];
        if ((int)$field['mandatory'] === 1) {
            $validators[] = ['identifier' => 'NotEmpty'];
        }
        $validationValidator = $this->validationValidator((int)$field['validation']);
        if ($validationValidator !== null) {
            $validators[] = $validationValidator;
        }
        if ($validators !== []) {
            $element['validators'] = $validators;
        }

        return $element;
    }

    /**
     * Powermail "validation" column -> ext:form validator definition.
     * Conservative mapping; types without a core validator fall back to a
     * RegularExpression. Returns null for "no validation".
     *
     * @return array<string, mixed>|null
     */
    private function validationValidator(int $validation): ?array
    {
        return match ($validation) {
            1 => ['identifier' => 'EmailAddress'],
            2 => ['identifier' => 'RegularExpression', 'options' => ['regularExpression' => '/^https?:\/\/.+/']],
            3 => ['identifier' => 'RegularExpression', 'options' => ['regularExpression' => '/^[0-9+\-\s()]+$/']],
            4 => ['identifier' => 'Number'],
            5 => ['identifier' => 'RegularExpression', 'options' => ['regularExpression' => '/^[A-Za-zÀ-ÿ\s]+$/']],
            default => null,
        };
    }

    /**
     * Parse Powermail option settings (one option per line,
     * "label | value | preselected") into ext:form's value => label map.
     *
     * @return array<string, string>
     */
    private function parseOptions(string $settings): array
    {
        $options = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($settings)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0];
            $value = ($parts[1] ?? '') !== '' ? $parts[1] : $label;
            $options[$value] = $label;
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function fieldIdentifier(array $field): string
    {
        $marker = $this->slugify((string)$field['marker']);
        if ($marker !== '') {
            return $marker;
        }
        $fromTitle = $this->slugify((string)$field['title']);
        return $fromTitle !== '' ? $fromTitle : ('field-' . $field['uid']);
    }

    /**
     * Powermail markers frequently already are HTML autocomplete tokens
     * (given-name, family-name, email, street-address …). Pass those through.
     *
     * @param array<string, mixed> $field
     */
    private function autocompleteToken(array $field): ?string
    {
        $known = [
            'name', 'given-name', 'additional-name', 'family-name', 'honorific-prefix',
            'honorific-suffix', 'nickname', 'organization', 'organization-title',
            'street-address', 'address-line1', 'address-line2', 'address-level1',
            'address-level2', 'country', 'country-name', 'postal-code', 'email',
            'tel', 'tel-national', 'url', 'bday', 'sex',
        ];
        $marker = trim((string)$field['marker']);
        if (in_array($marker, $known, true)) {
            return $marker;
        }
        $token = trim((string)($field['autocomplete_token'] ?? ''));
        return $token !== '' ? $token : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchForms(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_powermail_domain_model_form');
        $qb->getRestrictions()->removeAll();
        return $qb
            ->select('uid', 'pid', 'title')
            ->from('tx_powermail_domain_model_form')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->in('sys_language_uid', [0, -1]),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPages(int $formUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_powermail_domain_model_page');
        $qb->getRestrictions()->removeAll();
        return $qb
            ->select('uid', 'title', 'sorting')
            ->from('tx_powermail_domain_model_page')
            ->where(
                $qb->expr()->eq('form', $qb->createNamedParameter($formUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->in('sys_language_uid', [0, -1]),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFields(int $pageUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_powermail_domain_model_field');
        $qb->getRestrictions()->removeAll();
        return $qb
            ->select('*')
            ->from('tx_powermail_domain_model_field')
            ->where(
                $qb->expr()->eq('page', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->in('sys_language_uid', [0, -1]),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string)preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-');
    }

    private function relativePath(string $absolute): string
    {
        $root = Environment::getProjectPath() . '/';
        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
