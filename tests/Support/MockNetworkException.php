<?php

declare(strict_types=1);

namespace BillKit\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * A PSR-18 client-level exception used to simulate connection failures
 * (DNS, TLS, timeout) in tests. The transport maps any
 * {@see ClientExceptionInterface} to {@see \BillKit\Exception\ApiConnectionException}.
 */
final class MockNetworkException extends \RuntimeException implements ClientExceptionInterface
{
}
