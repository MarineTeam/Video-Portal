<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Forms\FormRepository;
use Portal\Forms\Submission;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Mail\MailProvider;
use Throwable;

/**
 * Filling a form in.
 *
 * NO ACCOUNT NEEDED. The people a connect card is for are exactly the ones who
 * have never made one, and a sign-in wall on the card that asks "how did you
 * find us" is the card asking a question it has already answered.
 *
 * A members-only form is INVISIBLE rather than refused — the same rule events
 * keep, and for the same reason: the title is a leak, and a refusal announces
 * that there is something there to be refused.
 */
final class FormController extends Controller
{
    public function index(Request $request): Response
    {
        $forms = $this->forms();

        return $this->view(['forms'], [
            'title' => 'Forms',
            'forms' => $forms->forms($this->isMember()),
            'flash' => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $form = $this->visibleForm((string) ($params['slug'] ?? ''));

        return $this->render($form, [], []);
    }

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        $this->verifyCsrf($request);

        $forms = $this->forms();
        $form = $this->visibleForm((string) ($params['slug'] ?? ''));

        if (!$form['is_open']) {
            // Checked again here rather than trusted from the page. A closed
            // form's page is still reachable by a link somebody kept.
            throw HttpException::notFound('There is no form here.');
        }

        $questions = $forms->questions((int) $form['id']);
        $read = Submission::read($questions, $request->post);

        if (!$read['ok']) {
            /*
             * Back to the form with what they wrote still in it. A validation
             * failure that empties the page is how somebody loses a paragraph
             * about why they are getting in touch, and they do not type it
             * again.
             */
            return $this->render($form, $read['errors'], $request->post);
        }

        $responseId = $forms->store(
            (int) $form['id'],
            $read['answers'],
            $this->user()?->id,
            $read['name'],
            $read['email']
        );

        $this->tell($form, $responseId);

        return $this->view(['form-thanks'], [
            'title'  => (string) $form['title'],
            'form'   => $form,
            'thanks' => $form['thanks'] ?: 'Thank you — that has reached us.',
        ]);
    }

    // ---------------------------------------------------------- internals

    /**
     * Tell whoever the FORM names.
     *
     * Different forms reach different people: a prayer-ministry card and a
     * car-park rota do not go to the same inbox, and one site-wide address
     * means somebody forwarding everything by hand until they stop.
     *
     * Failures are swallowed. The response is already stored and visible on the
     * screen that matters; turning a delivered form into an error page would
     * make somebody fill it in again, and the second copy is worse than a
     * missing email.
     *
     * @param array<string, mixed> $form
     */
    private function tell(array $form, int $responseId): void
    {
        $forms = $this->forms();
        $addresses = $forms->notifyAddresses($form);

        if ($addresses === []) {
            return;
        }

        try {
            $mail = $this->container->get(MailProvider::class);
            $base = rtrim((string) $this->config()->get('base_url', ''), '/');

            $subject = sprintf('%s: a new response', (string) $form['title']);
            $body = sprintf(
                '<p>Somebody has filled in <strong>%s</strong>.</p>'
                . '<p><a href="%s/admin/forms/%d">Read it and say who is dealing with it</a></p>'
                /*
                 * The answers are NOT in the email, deliberately. A connect
                 * card carries a phone number and often a good deal more, and
                 * an inbox is the wrong place for it: forwarded, kept for
                 * years, and readable by whoever picks up that account next.
                 * The link needs an account and the capability.
                 */
                . '<p style="color:#666;font-size:13px">The answers are on the site rather than in '
                . 'this email — what people write on these is not for an inbox.</p>',
                e((string) $form['title']),
                e($base),
                (int) $form['id']
            );

            foreach ($addresses as $address) {
                $mail->send($address, $subject, $body, strip_tags($body));
            }
        } catch (Throwable $e) {
            error_log('Could not tell anybody about form response ' . $responseId . ': ' . $e->getMessage());
        }
    }

    /**
     * The form, or a 404 — including when it exists and is not for you.
     *
     * One function, so no screen can decide this differently. The 404 for a
     * members-only form is the SAME 404 as for a form that does not exist.
     *
     * @return array<string, mixed>
     */
    private function visibleForm(string $slug): array
    {
        $form = $this->forms()->findBySlug($slug);

        if ($form === null || (!$form['is_open'] && !$this->canManage())) {
            throw HttpException::notFound('There is no form here.');
        }

        if ($form['member_only'] && !$this->isMember()) {
            throw HttpException::notFound('There is no form here.');
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<int, string> $errors
     * @param array<string, mixed> $given
     */
    private function render(array $form, array $errors, array $given): Response
    {
        // Most specific first: resolve() takes the first candidate that exists,
        // so a theme's form-{slug}.php has to be ahead of the general one or it
        // could never be found.
        return $this->view(['form-' . $form['slug'], 'form'], [
            'title'     => (string) $form['title'],
            'form'      => $form,
            'questions' => $this->forms()->questions((int) $form['id']),
            'errors'    => $errors,
            'given'     => $given,
            'token'     => $this->csrfToken(),
            'flash'     => $this->flash(),
        ]);
    }

    private function isMember(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->authorized);
    }

    private function canManage(): bool
    {
        return $this->guard()->can(\Portal\Auth\Capability::MANAGE_FORMS);
    }

    private function forms(): FormRepository
    {
        return new FormRepository($this->db());
    }
}
