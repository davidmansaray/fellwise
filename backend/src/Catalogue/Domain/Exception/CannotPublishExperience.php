<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain\Exception;

use DomainException;

final class CannotPublishExperience extends DomainException
{
    public static function missing(string $field): self
    {
        return new self("An experience needs a {$field} before it can be published.");
    }

    public static function notPublished(): self
    {
        return new self('Only a published experience can be unpublished.');
    }
}
