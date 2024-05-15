<?php

namespace App\Resolver;

use App\Attributes\EncryptFile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

readonly class RequestPayloadValueResolver implements ValueResolverInterface, EventSubscriberInterface
{

    public function __construct(
        private ValidatorInterface $validator,
        private string $directory,
        private string $secret
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => 'onKernelControllerArguments',
        ];
    }

    /**
     * @throws \SodiumException
     */
    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        $arguments = $event->getArguments();

        foreach ($arguments as $i => $argument) {
            if (!$argument instanceof EncryptFile) {
                continue;
            }
            $payloadMapper = $this->getFile(...);
            $validationFailedCode = $argument->validationFailedStatusCode;

            $request = $event->getRequest();

            if (!$argument->metadata->getType()) {
                throw new \LogicException(
                    sprintf(
                        'Could not resolve the "$%s" controller argument: argument should be typed.',
                        $argument->metadata->getName()
                    )
                );
            }


            $payload = $payloadMapper($request, $argument->metadata);
            $violations = $this->validator->validate(value: $payload, constraints: [
                new Assert\File(mimeTypes: ['text/plain']),
            ]);
            if (\count($violations)) {
                throw HttpException::fromStatusCode(
                    $validationFailedCode,
                    implode("\n", array_map(static fn($e) => $e->getMessage(), iterator_to_array($violations))),
                    new ValidationFailedException($payload, $violations)
                );
            }

            if ($payload instanceof UploadedFile && $argument instanceof EncryptFile) {
                $payload = $this->uploadToDirectory($payload);
                $payload = $this->encryptFile($payload);
            }

            $arguments[$i] = $payload;

            $event->setArguments($arguments);
        }
    }

    public function resolve(
        Request $request,
        ArgumentMetadata $argument
    ): iterable {
        $attribute = $argument->getAttributesOfType(EncryptFile::class, ArgumentMetadata::IS_INSTANCEOF)[0]
            ?? null;


        if (!$attribute) {
            return [];
        }
        $attribute->metadata = $argument;

        return [$attribute];
    }

    public function getFile(
        Request $request,
        ArgumentMetadata $argument
    ) {
        return $request->files->get($argument->getName());
    }

    /**
     * @throws \SodiumException
     * @throws \Exception
     */
    public function encryptFile(
        string $filename,
        string $dirName = '/encrypted'
    ): string {
        $filename = $this->directory.$dirName.'/'.$filename;
        $plainText = file_get_contents($filename);


        if (!$plainText) {
            throw new FileException('File has no content');
        }
        if (ctype_xdigit($plainText)) {
            throw new FileException('file is already encrypted');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = hash('sha256', $this->secret, true);


        $ciphertext = sodium_crypto_secretbox($plainText, $nonce, $key);

        $output = bin2hex($nonce.$ciphertext);

        try {
            $file = fopen($filename, 'w');
            fwrite($file, $output);
        } finally {
            fclose($file);
        }

        return $output;
    }

    private function uploadToDirectory(UploadedFile $file, string $dirName = '/encrypted'): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $slugger = new AsciiSlugger();
        $safeFilename = $slugger->slug($originalFilename);
        $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $path = $this->directory.'/'.$dirName;

        try {
            $file->move($path, $newFilename);
        } catch (FileException $e) {
            throw new FileException(
                sprintf('An error occurred while uploading the file to the %s directory.', $dirName)
            );
        }

        return $newFilename;
    }
}