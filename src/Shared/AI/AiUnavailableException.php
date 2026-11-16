<?php

namespace Kai\Tools\Shared\AI;

use RuntimeException;

/**
 * Signalisiert, dass die KI-API vorübergehend nicht erreichbar oder überlastet ist (z. B. HTTP 429/503).
 */
class AiUnavailableException extends RuntimeException
{
}
