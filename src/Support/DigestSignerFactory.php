<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Support;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Signing\DigestSigner;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;

/**
 * Builds the digest signer for a credential set. HS256 today; bind your own subclass in the
 * container to swap the digest step (the request signer around it stays a pure function).
 */
class DigestSignerFactory
{
    public function __invoke(Credentials $credentials): DigestSigner
    {
        return new Hs256DigestSigner($credentials->secret());
    }
}
