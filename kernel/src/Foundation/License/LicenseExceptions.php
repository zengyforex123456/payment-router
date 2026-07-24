<?php
/** License exceptions — single responsibility: error types */
declare(strict_types=1);
namespace Converge\Foundation\License;
class LicenseException extends \RuntimeException {}
class NetworkException extends \RuntimeException {}
class LicenseRevokedException extends \RuntimeException {}
