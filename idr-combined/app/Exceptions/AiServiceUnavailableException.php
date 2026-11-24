<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The AI tier (Gemini / Qdrant) failed while serving a request.
 * Rendered by bootstrap/app.php as a 502 JSON error.
 *
 * Use forFailure() so the person sees WHAT went wrong in plain words (search
 * index unreachable, AI model busy, keys not set up…) instead of one generic
 * line. The technical error stays in storage/logs/laravel.log.
 */
class AiServiceUnavailableException extends HttpException
{
    public function __construct(string $message = 'The AI service is temporarily unavailable. Please try again.', ?Throwable $previous = null)
    {
        parent::__construct(502, $message, $previous);
    }

    /** @param  string  $feature  what the user was doing, e.g. "The AI assistant" or "Search" */
    public static function forFailure(Throwable $e, string $feature = 'The AI assistant'): self
    {
        return new self(self::describe($e->getMessage(), $feature), $e);
    }

    public static function describe(string $error, string $feature = 'The AI assistant'): string
    {
        $e = strtolower($error);
        $unreachable = str_contains($e, 'curl error 28') || str_contains($e, 'timed out') || str_contains($e, 'curl error 6')
            || str_contains($e, 'curl error 7') || str_contains($e, 'could not resolve') || str_contains($e, 'connection refused');

        return match (true) {
            str_contains($e, 'not configured') || str_contains($e, 'api key not valid') || str_contains($e, 'api_key_invalid')
                || preg_match('/failed \((401|403)\)/', $e) === 1
                => "{$feature} is not set up correctly: an AI service key is missing or invalid. Please tell an administrator.",
            str_contains($e, 'qdrant') && $unreachable
                => "{$feature} can't reach the document search service (Qdrant) right now. Please try again in a few minutes.",
            str_contains($e, 'qdrant')
                => "{$feature} hit an error in the document search service (Qdrant). Please try again in a few minutes.",
            str_contains($e, 'high demand') || str_contains($e, 'overloaded') || preg_match('/failed \((429|500|502|503|504)\)/', $e) === 1
                => 'The AI model (Gemini) is busy right now. Please try again in a minute.',
            $unreachable
                => "{$feature} took too long to get an answer from the AI service. Please try again.",
            default => "{$feature} is temporarily unavailable. Please try again.",
        };
    }
}
