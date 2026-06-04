<?php

namespace App\Email;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders the markdown body of an outgoing email into both an HTML version
 * (with sane defaults: autolinks, tables, strikethrough; no raw HTML allowed
 * in input) and a clean plain-text fallback.
 */
class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Convert markdown to a self-contained HTML document ready to be used as
     * the HTML alternative of an email. Inlines minimal styling so that mail
     * clients don't have to interpret CSS classes.
     */
    public function toHtml(string $markdown): string
    {
        $rendered = (string) $this->converter->convert($markdown);

        return $this->wrap($rendered);
    }

    /**
     * Return the plain-text version of the body. Markdown is human-readable
     * by design, so we keep it almost as-is. We just normalize line endings
     * and strip any \r so SMTP can handle it consistently.
     */
    public function toText(string $markdown): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);

        return rtrim($normalized) . "\n";
    }

    private function wrap(string $bodyHtml): string
    {
        $style = <<<'CSS'
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #1a1a1a; margin: 0; padding: 0; }
            .email-body { max-width: 720px; margin: 0; padding: 0; }
            p { margin: 0 0 1em 0; }
            a { color: #2563eb; }
            blockquote { margin: 1em 0; padding: 0 0 0 1em; border-left: 3px solid #d1d5db; color: #4b5563; }
            pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; }
            pre { background: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; }
            code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }
            pre code { background: transparent; padding: 0; }
            table { border-collapse: collapse; margin: 1em 0; }
            th, td { border: 1px solid #d1d5db; padding: 6px 10px; text-align: left; }
            hr { border: none; border-top: 1px solid #e5e7eb; margin: 1.5em 0; }
            CSS;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>{$style}</style>
            </head>
            <body>
            <div class="email-body">{$bodyHtml}</div>
            </body>
            </html>
            HTML;
    }
}
