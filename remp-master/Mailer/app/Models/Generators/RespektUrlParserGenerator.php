<?php

namespace Remp\Mailer\Models\Generators;

use Nette\Application\UI\Form;
use Nette\Utils\ArrayHash;
use Remp\Mailer\Components\GeneratorWidgets\Widgets\RespektUrlParserWidget\RespektUrlParserWidget;
use Remp\Mailer\Models\PageMeta\Content\RespektContent;
use Remp\MailerModule\Models\ContentGenerator\Engine\EngineFactory;
use Remp\MailerModule\Models\Generators\IGenerator;
use Remp\MailerModule\Models\PageMeta\Content\ContentInterface;
use Remp\MailerModule\Models\PageMeta\Content\InvalidUrlException;
use Remp\MailerModule\Repositories\SourceTemplatesRepository;
use Tomaj\NetteApi\Params\PostInputParam;
use Tracy\Debugger;
use Tracy\ILogger;

class RespektUrlParserGenerator implements IGenerator
{
    public $onSubmit;

    public function __construct(
        private readonly ContentInterface $content,
        private readonly SourceTemplatesRepository $sourceTemplatesRepository,
        private readonly EngineFactory $engineFactory
    ) {
    }

    public function generateForm(Form $form): void
    {
        $form->addTextArea('articles', 'Article')
            ->setHtmlAttribute('rows', 7)
            ->setOption('description', 'Paste article URLs. Each on separate line.')
            ->setRequired(true)
            ->getControlPrototype()
            ->setHtmlAttribute('class', 'form-control html-editor');

        $form->onSuccess[] = [$this, 'formSucceeded'];
    }

    public function onSubmit(callable $onSubmit): void
    {
        $this->onSubmit = $onSubmit;
    }

    public function formSucceeded(Form $form, ArrayHash $values): void
    {
        if (!$this->content instanceof RespektContent) {
            Debugger::log(self::class . ' depends on ' . RespektContent::class . '.', ILogger::ERROR);
            $sourceTemplate = null;
            if (isset($values['source_template_id'])) {
                $sourceTemplate = $this->sourceTemplatesRepository->find($values['source_template_id']);
            }
            $form->addError(sprintf(
                "Mail generator [%s] is not configured correctly. Contact developers.",
                $sourceTemplate->title ?? '',
            ));
            return;
        }

        try {
            $output = $this->process((array) $values);

            $addonParams = [
                'render' => true,
                'errors' => $output['errors'],
            ];

            $this->onSubmit->__invoke($output['htmlContent'], $output['textContent'], $addonParams);
        } catch (InvalidUrlException $e) {
            $form->addError($e->getMessage());
        }
    }

    public function apiParams(): array
    {
        return [
            (new PostInputParam('articles'))->setRequired(),
            (new PostInputParam('utm_campaign'))->setRequired(),
        ];
    }

    public function process(array $values): array
    {
        $sourceTemplate = $this->sourceTemplatesRepository->find($values['source_template_id']);

        $items = [];
        $errors = [];

        $urls = explode("\n", trim($values['articles']));
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) {
                // people sometimes enter blank lines
                continue;
            }
            try {
                $meta = $this->content->fetchUrlMeta($url);
                if ($meta) {
                    $items[$url] = $meta;
                } else {
                    $errors[] = $url;
                }
            } catch (InvalidUrlException $e) {
                $errors[] = $url;
            }
        }

        $params = [
            'items' => $items,
        ];

        $engine = $this->engineFactory->engine();
        return [
            'htmlContent' => $engine->render($sourceTemplate->content_html, $params),
            'textContent' => strip_tags($engine->render($sourceTemplate->content_text, $params)),
            'errors' => $errors,
        ];
    }

    public function getWidgets(): array
    {
        return [RespektUrlParserWidget::class];
    }

    public function preprocessParameters($data): ?ArrayHash
    {
        return null;
    }
}
