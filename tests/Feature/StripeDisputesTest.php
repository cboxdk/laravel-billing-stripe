<?php

declare(strict_types=1);

use Cbox\Billing\Stripe\Contracts\StripeDisputes;
use Cbox\Billing\Stripe\Exceptions\StripeDisputeFailed;
use Cbox\Billing\Stripe\StripeApiDisputes;
use Cbox\Billing\Stripe\StripeDisputeEvidence;
use Cbox\Billing\Stripe\StripeServiceProvider;
use Cbox\Billing\Stripe\Testing\FakeStripeDisputes;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * A recording HTTP client: every request the SDK makes is kept, and each answer is the next body
 * queued, so the real {@see StripeApiDisputes} is exercised with no network.
 */
final class RecordingStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: array<array-key, mixed>, hasFile: bool}> */
    public array $requests = [];

    /** @param  list<array{0: int, 1: array<string, mixed>}>  $answers */
    public function __construct(public array $answers) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => (string) $method, 'url' => (string) $absUrl, 'params' => (array) $params, 'hasFile' => (bool) $hasFile];
        [$status, $body] = array_shift($this->answers) ?? [500, ['error' => ['message' => 'no answer queued']]];

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }
}

/** @return array<string, mixed> */
function stripeDispute(array $overrides = []): array
{
    return array_replace([
        'id' => 'du_123',
        'object' => 'dispute',
        'amount' => 12500,
        'currency' => 'dkk',
        'charge' => 'ch_1',
        'payment_intent' => 'pi_1',
        'reason' => 'fraudulent',
        'status' => 'needs_response',
        'evidence_details' => ['due_by' => 1_800_000_000, 'has_evidence' => false, 'past_due' => false, 'submission_count' => 0],
    ], $overrides);
}

afterEach(function (): void {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

it('reads a dispute onto the seam shape, with the deadline from evidence_details', function () {
    expect(StripeDisputeEvidence::map(stripeDispute()))->toBe([
        'id' => 'du_123',
        'status' => 'needs_response',
        'reason' => 'fraudulent',
        'amountMinor' => 12500,
        'currency' => 'DKK',
        'charge' => 'ch_1',
        'paymentIntent' => 'pi_1',
        'dueBy' => 1_800_000_000,
        'hasEvidence' => false,
        'pastDue' => false,
        'submissionCount' => 0,
    ]);
});

it('reads an expanded charge and a dispute with no deadline', function () {
    $mapped = StripeDisputeEvidence::map(stripeDispute(['charge' => ['id' => 'ch_9'], 'payment_intent' => null, 'evidence_details' => ['due_by' => null]]));

    expect($mapped['charge'])->toBe('ch_9')
        ->and($mapped['paymentIntent'])->toBe('')
        ->and($mapped['dueBy'])->toBeNull();
});

it('names the evidence keys Stripe does not take', function () {
    expect(StripeDisputeEvidence::unknown(['receipt' => 'file_1', 'uncategorized_text' => 'x', 'shipping_carrier' => 'DHL', 'nonsense' => 'y']))
        ->toBe(['shipping_carrier', 'nonsense']);
});

it('retrieves a dispute through the SDK', function () {
    $http = new RecordingStripeHttp([[200, stripeDispute()]]);
    ApiRequestor::setHttpClient($http);

    $dispute = (new StripeApiDisputes(new StripeClient('sk_test_offline')))->retrieve('du_123');

    expect($dispute['status'])->toBe('needs_response')
        ->and($http->requests[0]['method'])->toBe('get')
        ->and($http->requests[0]['url'])->toEndWith('/v1/disputes/du_123');
});

it('submits evidence and reads the dispute Stripe returns', function () {
    $http = new RecordingStripeHttp([[200, stripeDispute(['status' => 'under_review', 'evidence_details' => ['due_by' => 1_800_000_000, 'has_evidence' => true, 'submission_count' => 1]])]]);
    ApiRequestor::setHttpClient($http);

    $dispute = (new StripeApiDisputes(new StripeClient('sk_test_offline')))->submitEvidence('du_123', [
        'uncategorized_text' => 'The customer used the service all month.',
        'receipt' => 'file_1',
    ]);

    expect($dispute['status'])->toBe('under_review')
        ->and($dispute['submissionCount'])->toBe(1)
        ->and($dispute['hasEvidence'])->toBeTrue()
        ->and($http->requests[0]['method'])->toBe('post')
        ->and($http->requests[0]['url'])->toEndWith('/v1/disputes/du_123')
        ->and($http->requests[0]['params'])->toBe([
            'evidence' => ['uncategorized_text' => 'The customer used the service all month.', 'receipt' => 'file_1'],
            // The SDK sends a boolean as Stripe's form encoding writes it.
            'submit' => 'true',
        ]);
});

it('stages evidence without submitting when asked to', function () {
    $http = new RecordingStripeHttp([[200, stripeDispute()]]);
    ApiRequestor::setHttpClient($http);

    (new StripeApiDisputes(new StripeClient('sk_test_offline')))->submitEvidence('du_123', ['uncategorized_text' => 'draft'], submit: false);

    expect($http->requests[0]['params']['submit'])->toBe('false');
});

it('refuses an unknown evidence key before calling Stripe', function () {
    $http = new RecordingStripeHttp([]);
    ApiRequestor::setHttpClient($http);

    expect(fn () => (new StripeApiDisputes(new StripeClient('sk_test_offline')))->submitEvidence('du_123', ['shipping_carrier' => 'DHL']))
        ->toThrow(StripeDisputeFailed::class, 'shipping_carrier');

    expect($http->requests)->toBe([]);
});

it('turns a Stripe refusal into a dispute failure', function () {
    ApiRequestor::setHttpClient(new RecordingStripeHttp([[400, ['error' => ['type' => 'invalid_request_error', 'message' => 'This dispute is already closed']]]]));

    expect(fn () => (new StripeApiDisputes(new StripeClient('sk_test_offline')))->submitEvidence('du_123', ['uncategorized_text' => 'x']))
        ->toThrow(StripeDisputeFailed::class, 'already closed');
});

it('uploads an evidence file with the dispute_evidence purpose and leaves no temporary file behind', function () {
    $http = new RecordingStripeHttp([[200, ['id' => 'file_abc', 'object' => 'file', 'purpose' => 'dispute_evidence']]]);
    ApiRequestor::setHttpClient($http);
    $before = glob(sys_get_temp_dir().'/cbox-dispute-*') ?: [];

    $id = (new StripeApiDisputes(new StripeClient('sk_test_offline')))->uploadEvidenceFile('../../invoice 42.pdf', '%PDF-1.4 test');

    expect($id)->toBe('file_abc')
        ->and($http->requests[0]['url'])->toEndWith('/v1/files')
        ->and($http->requests[0]['hasFile'])->toBeTrue()
        ->and($http->requests[0]['params']['purpose'])->toBe('dispute_evidence')
        ->and(glob(sys_get_temp_dir().'/cbox-dispute-*') ?: [])->toBe($before);
});

it('binds the real disputes seam when a secret is configured', function () {
    config()->set('billing-stripe.secret', 'sk_test_offline');
    (new StripeServiceProvider(app()))->register();

    expect(app(StripeDisputes::class))->toBeInstanceOf(StripeApiDisputes::class);
});

it('scripts disputes in the fake, moving a submission to under review once', function () {
    $fake = new FakeStripeDisputes;
    $fake->add('du_fake', dueBy: 1_800_000_000);

    $file = $fake->uploadEvidenceFile('receipt.pdf', '%PDF');
    $dispute = $fake->submitEvidence('du_fake', ['receipt' => $file]);

    expect($file)->toBe('file_test_1')
        ->and($dispute['status'])->toBe('under_review')
        ->and($dispute['submissionCount'])->toBe(1)
        ->and($fake->submissions)->toHaveCount(1)
        ->and(fn () => $fake->submitEvidence('du_fake', ['uncategorized_text' => 'again']))->toThrow(StripeDisputeFailed::class);
});

it('fails the next fake call on request', function () {
    $fake = new FakeStripeDisputes;
    $fake->add('du_fake');
    $fake->failNext('down');

    expect(fn () => $fake->retrieve('du_fake'))->toThrow(StripeDisputeFailed::class, 'down')
        ->and($fake->retrieve('du_fake')['id'])->toBe('du_fake');
});
