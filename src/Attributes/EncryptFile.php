<?php

namespace App\Attributes;

use App\Resolver\RequestPayloadValueResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class EncryptFile extends ValueResolver
{

    public ArgumentMetadata $metadata;

    public function __construct(
        public ?string $name = null,
        string $resolver = RequestPayloadValueResolver::class,
        public readonly int $validationFailedStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($resolver);
    }
}