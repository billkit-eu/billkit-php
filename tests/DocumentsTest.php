<?php

declare(strict_types=1);

namespace BillKit\Tests;

use BillKit\Exception\ServerException;
use BillKit\Tests\Support\MockHttpClient;

/**
 * The document routes: invoice and credit-note PDFs.
 *
 * These are the only calls that return bytes rather than JSON, and the only
 * ones that follow a redirect. Both differences are where the mistakes live:
 * handing back a JSON-decoded husk instead of a PDF, losing the typed
 * exception when a deployment has no renderer, and — the one that matters —
 * carrying the API key across to the storage host the redirect points at.
 *
 * These run through the PSR-18 seam, which is also the transport that has to
 * resolve the redirect itself: an injected client's redirect policy is its
 * own, and the ones that follow may replay the Authorization header.
 */
final class DocumentsTest extends BillKitTestCase
{
    private const PDF = "%PDF-1.7\nnot json\n";

    public function testInvoicePdfReturnsRawBytes(): void
    {
        $http = (new MockHttpClient())->stage(200, self::PDF, ['content-type' => 'application/pdf']);
        $client = $this->makeClient($http);

        self::assertSame(self::PDF, $client->invoices->retrievePdf('inv_1'));
        self::assertSame(
            self::BASE_URL . '/v1/invoices/inv_1/pdf',
            (string) $http->requests[0]->getUri(),
        );
    }

    public function testCreditNotePdfReturnsRawBytes(): void
    {
        $http = (new MockHttpClient())->stage(200, self::PDF);
        $client = $this->makeClient($http);

        self::assertSame(self::PDF, $client->creditNotes->retrievePdf('cn_1'));
        self::assertSame(
            self::BASE_URL . '/v1/credit_notes/cn_1/pdf',
            (string) $http->requests[0]->getUri(),
        );
    }

    /** S3-backed deployments answer 302 to a presigned URL. */
    public function testPdfFollowsTheStorageRedirect(): void
    {
        $http = (new MockHttpClient())
            ->stage(302, ' ', ['location' => 'https://s3.test/obj?sig=abc'])
            ->stage(200, self::PDF);
        $client = $this->makeClient($http);

        self::assertSame(self::PDF, $client->invoices->retrievePdf('inv_1'));
        self::assertCount(2, $http->requests);
        self::assertSame('https://s3.test/obj?sig=abc', (string) $http->requests[1]->getUri());
    }

    /**
     * The one that matters.
     *
     * The presigned URL carries its own credential. Handing the storage host
     * BillKit's API key as well would hand a third party a live secret, and
     * it would be in their access logs.
     */
    public function testPdfRedirectDoesNotCarryTheApiKey(): void
    {
        $http = (new MockHttpClient())
            ->stage(302, ' ', ['location' => 'https://s3.test/obj?sig=abc'])
            ->stage(200, self::PDF);
        $client = $this->makeClient($http);

        $client->invoices->retrievePdf('inv_1');

        self::assertNotSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('', $http->requests[1]->getHeaderLine('Authorization'));
    }

    /**
     * A relative Location is not followed.
     *
     * The storage adapters always answer with an absolute URL; resolving a
     * relative one against the API host would point the hop back at us rather
     * than at the object, and re-send the credential while doing it.
     */
    public function testPdfDoesNotFollowARelativeRedirect(): void
    {
        $http = (new MockHttpClient())->stage(302, ' ', ['location' => '/somewhere/else']);
        $client = $this->makeClient($http);

        try {
            $client->invoices->retrievePdf('inv_1');
            self::fail('a 302 the transport will not follow must surface, not be swallowed');
        } catch (\BillKit\Exception\BillKitException) {
            self::assertCount(1, $http->requests);
        }
    }

    /** A storage adapter stuck in a loop must fail, not hang. */
    public function testPdfDoesNotFollowARedirectForever(): void
    {
        $http = new MockHttpClient();
        // One more than MAX_REDIRECTS, then a body that would satisfy the
        // caller if the cap were not enforced.
        for ($i = 0; $i < 8; $i++) {
            $http->stage(302, ' ', ['location' => 'https://s3.test/loop']);
        }
        $client = $this->makeClient($http, $this->fastRetry(1));

        try {
            $client->invoices->retrievePdf('inv_1');
            self::fail('expected the redirect cap to surface the 302');
        } catch (\BillKit\Exception\BillKitException) {
            // 1 initial + 5 hops. The cap is the transport's, not the mock's.
            self::assertCount(6, $http->requests);
        }
    }

    /**
     * ``INVOICE_PDF_ENABLED=false`` answers 501 with the normal envelope.
     *
     * An error is an error envelope whichever endpoint produced it, so the
     * document routes must throw the same typed exception as everything else
     * rather than hand back a body that is not a PDF.
     */
    public function testPdfStillThrowsTheTypedException(): void
    {
        $envelope = ['error' => ['type' => 'api_error', 'code' => 'rendering_pending', 'message' => 'off']];
        $http = (new MockHttpClient())->stage(501, $envelope);
        $client = $this->makeClient($http, $this->fastRetry(1));

        try {
            $client->invoices->retrievePdf('inv_1');
            self::fail('expected ServerException');
        } catch (ServerException $err) {
            self::assertSame('rendering_pending', $err->errorCode);
            self::assertSame(501, $err->statusCode);
        }
    }

    /** A GET is not a mutation; a key on one would be noise in the ledger. */
    public function testPdfSendsNoIdempotencyKey(): void
    {
        $http = (new MockHttpClient())->stage(200, self::PDF);
        $this->makeClient($http)->invoices->retrievePdf('inv_1');

        self::assertSame('', $http->requests[0]->getHeaderLine('Idempotency-Key'));
    }
}
