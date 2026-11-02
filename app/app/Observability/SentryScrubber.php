<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Keeps training examples and credentials out of Sentry. Training data may
 * contain customer text; worker bearer tokens and Anthropic/HF API keys appear
 * in exception context. Referenced from config/sentry.php as [class, method]
 * so config:cache keeps working.
 */
final class SentryScrubber
{
    private const SCRUBBED = '[scrubbed]';

    /** Request headers worth keeping for debugging; everything else (cookies, tokens) goes. */
    private const KEEP_HEADERS = ['user-agent', 'content-type', 'accept', 'content-length'];

    public static function event(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            unset($request['data'], $request['cookies'], $request['env'], $request['query_string']);

            if (isset($request['url'])) {
                $request['url'] = self::scrubPath((string) strtok((string) $request['url'], '?'));
            }

            $request['headers'] = array_intersect_key(
                $request['headers'] ?? [],
                array_flip(array_merge(self::KEEP_HEADERS, array_map('ucwords', self::KEEP_HEADERS))),
            );
            $event->setRequest($request);
        }

        // Frame variables hold worker tokens, API keys, training example content.
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrub($exception->getValue()));

            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        foreach ($event->getStacktrace()?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }

        $event->setExtra([]);

        if ($event->getMessage() !== null) {
            $event->setMessage(self::scrub($event->getMessage()));
        }

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $b): Breadcrumb => self::breadcrumb($b),
            $event->getBreadcrumbs(),
        ));

        return $event;
    }

    public static function breadcrumb(Breadcrumb $breadcrumb, mixed $hint = null): Breadcrumb
    {
        $category = $breadcrumb->getCategory();

        // Log entries ("log.error", "log.warning") carry free text and context.
        if (str_starts_with($category, 'log')) {
            return new Breadcrumb($breadcrumb->getLevel(), $breadcrumb->getType(), $category, self::SCRUBBED, [], $breadcrumb->getTimestamp());
        }

        // Drop metadata whole (SQL bindings, job payloads, request URLs with tokens).
        $metadata = [];
        $url = $breadcrumb->getMetadata()['url'] ?? null;

        if (is_string($url)) {
            $metadata['url'] = self::scrubPath((string) strtok($url, '?'));
        }

        $message = $breadcrumb->getMessage();

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $category,
            $message === null ? null : self::scrub($message),
            $metadata,
            $breadcrumb->getTimestamp(),
        );
    }

    /** Inlined SQL, API keys (sk-ant-, hf_), and email addresses are masked. */
    public static function scrub(string $text): string
    {
        // Inlined SQL from QueryException
        $text = preg_replace('/SQL: .*$/s', 'SQL: '.self::SCRUBBED.')', $text) ?? self::SCRUBBED;

        // Anthropic and HuggingFace API keys
        $text = preg_replace('/\bsk-ant-[A-Za-z0-9\-_]{10,}\b/', self::SCRUBBED, $text) ?? self::SCRUBBED;
        $text = preg_replace('/\bhf_[A-Za-z0-9]{10,}\b/', self::SCRUBBED, $text) ?? self::SCRUBBED;

        // Email addresses (training examples may contain customer contact info)
        return preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[a-z]{2,}/iu', self::SCRUBBED, $text) ?? self::SCRUBBED;
    }

    /** A long opaque path segment is a token: /runs/<id>/jobs/<token>. */
    public static function scrubPath(string $url): string
    {
        return preg_replace('#/[A-Za-z0-9_\-]{24,}(?=/|$)#', '/'.self::SCRUBBED, $url) ?? $url;
    }
}
