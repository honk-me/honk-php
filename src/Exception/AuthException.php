<?php

declare(strict_types=1);

namespace HonkMe\Exception;

/** 401/403: invalid or revoked key, priority_not_allowed (urgent without allow_urgent), project or workspace suspended. */
class AuthException extends HonkException
{
}
