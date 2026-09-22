<?php

namespace App\Services\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Email HTML, made safe to show.
 *
 * Mail is written by strangers, and HTML mail is a web page they get to put
 * on somebody's screen. What arrives is stripped of everything that runs or
 * reaches out on its own - scripts, frames, forms, event handlers,
 * javascript: links - before it is stored, and the screen shows what is left
 * inside a sandboxed frame with a content policy that blocks remote images
 * until the reader asks for them (see MailReader on the front end). Two
 * locks, because either one alone has been got round before.
 */
class MailHtml
{
    private const DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
        'input', 'button', 'select', 'textarea', 'base', 'link', 'meta', 'noscript', 'svg', 'math'];

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The meta charset is how DOMDocument is told the bytes are UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8"><meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*')) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = strtolower(preg_replace('/\s+/', '', $attribute->value) ?? '');

                if (str_starts_with($name, 'on')
                    || (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href', 'background'], true)
                        && (str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:') || str_starts_with($value, 'data:text/html')))
                    || ($name === 'style' && (str_contains($value, 'expression(') || str_contains($value, 'javascript:')))) {
                    $node->removeAttribute($attribute->name);
                }
            }

            // Every link leaves in a new tab, and tells the other side nothing about where it came from.
            if (strtolower($node->nodeName) === 'a' && $node->hasAttribute('href')) {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $out = '';
        if ($body) {
            foreach ($body->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return $out;
    }

    /** The first line or so of a mail, as the list shows it. */
    public static function snippet(?string $text, ?string $html): string
    {
        $source = filled($text) ? $text : strip_tags((string) preg_replace('/<(style|script)[^>]*>.*?<\/\1>/is', ' ', (string) $html));
        $flat = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $source, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strimwidth($flat, 0, 200, '…');
    }

    /** Plain text, as HTML that keeps its lines - for mail that came without HTML. */
    public static function fromText(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $linked = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $escaped);

        return '<div style="white-space:pre-wrap">' . $linked . '</div>';
    }
}
